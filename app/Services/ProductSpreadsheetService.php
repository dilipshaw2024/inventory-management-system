<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class ProductSpreadsheetService
{
    public const HEADERS = ['id', 'name', 'sku', 'barcode', 'supplier_id', 'unit_id', 'category_id', 'brand_id', 'hsn_sac_code', 'purchase_price', 'sales_price', 'min_stock', 'max_stock', 'reorder_level', 'tax_rate', 'tax_rate_id', 'weight_kg', 'length_m', 'width_m', 'height_m', 'tracking_type', 'product_type', 'lifecycle_status', 'can_purchase', 'can_sell', 'is_stock_item', 'status'];

    public function read(string $path, string $extension): array
    {
        if ($extension !== 'xlsx') {
            $handle = fopen($path, 'r');
            $headers = array_map(fn ($header) => strtolower(trim((string) $header)), fgetcsv($handle) ?: []);
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) $rows[] = array_combine($headers, array_slice(array_pad($values, count($headers), null), 0, count($headers))) ?: [];
            fclose($handle);
            return $rows;
        }
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('XLSX import requires the PHP Zip extension.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('The XLSX file could not be opened.');
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            foreach ($document?->si ?? [] as $item) $shared[] = (string) ($item->t ?? '');
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) throw new RuntimeException('The XLSX file has no readable first worksheet.');
        $document = simplexml_load_string($sheet, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if (!$document) throw new RuntimeException('The XLSX worksheet is invalid.');
        $rows = [];
        foreach ($document->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c ?? [] as $cell) {
                preg_match('/^[A-Z]+/', (string) $cell['r'], $match);
                $column = $this->columnNumber($match[0] ?? 'A');
                $value = (string) ($cell->v ?? '');
                if ((string) $cell['t'] === 's') $value = $shared[(int) $value] ?? '';
                elseif ((string) $cell['t'] === 'inlineStr') $value = (string) ($cell->is->t ?? '');
                $values[$column] = $value;
            }
            if ($values) { ksort($values); $rows[] = array_values(array_replace(array_fill(0, max(array_keys($values)) + 1, ''), $values)); }
        }
        if (!$rows) return [];
        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), array_shift($rows));
        return array_map(fn ($values) => array_combine($headers, array_slice(array_pad($values, count($headers), null), 0, count($headers))) ?: [], $rows);
    }

    public function write(array $headers, iterable $rows): string
    {
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('XLSX export requires the PHP Zip extension.');
        $sheetRows = [$headers]; foreach ($rows as $row) $sheetRows[] = $row;
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($sheetRows as $rowNumber => $row) {
            $sheet .= '<row r="'.($rowNumber + 1).'">';
            foreach (array_values($row) as $column => $value) $sheet .= '<c r="'.$this->columnName($column + 1).($rowNumber + 1).'" t="inlineStr"><is><t>'.htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8').'</t></is></c>';
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';
        $tmp = tempnam(sys_get_temp_dir(), 'product-xlsx-'); $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('The XLSX file could not be created.');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet); $zip->close();
        $content = file_get_contents($tmp) ?: ''; unlink($tmp); return $content;
    }

    private function columnNumber(string $letters): int { $number = 0; foreach (str_split($letters) as $letter) $number = $number * 26 + ord($letter) - 64; return max(1, $number); }
    private function columnName(int $number): string { $name = ''; while ($number > 0) { $name = chr(65 + (($number - 1) % 26)).$name; $number = intdiv($number - 1, 26); } return $name; }
}
