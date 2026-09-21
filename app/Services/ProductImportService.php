<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImportService
{
    private const FIELDS = ['name', 'sku', 'barcode', 'supplier_id', 'unit_id', 'category_id', 'brand_id', 'hsn_sac_code', 'purchase_price', 'sales_price', 'min_stock', 'max_stock', 'reorder_level', 'tax_rate', 'tax_rate_id', 'weight_kg', 'length_m', 'width_m', 'height_m', 'tracking_type', 'product_type', 'lifecycle_status', 'can_purchase', 'can_sell', 'is_stock_item', 'status'];

    /** @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>} */
    public function validateRows(array $importRows, ?int $companyId): array
    {
        $headers = array_keys($importRows[0] ?? []); $required = ['name', 'unit_id', 'category_id']; $errors = []; $rows = [];
        foreach ($required as $field) if (!in_array($field, $headers, true)) $errors[] = "Header {$field} is required.";
        if (count($headers) !== count(array_unique($headers))) $errors[] = 'CSV headers must be unique.';
        $seenSkus = []; $seenBarcodes = []; $line = 1;
        foreach ($importRows as $row) {
            $line++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            foreach ($required as $field) if (blank($row[$field] ?? null)) $errors[] = "Row {$line}: {$field} is required.";
            if (!empty($row['tracking_type']) && !in_array($row['tracking_type'], ['none', 'batch', 'serial'], true)) $errors[] = "Row {$line}: tracking_type must be none, batch, or serial.";
            if (!empty($row['product_type']) && !in_array($row['product_type'], ['stock', 'service', 'consumable', 'asset', 'bundle'], true)) $errors[] = "Row {$line}: product_type is invalid.";
            if (!empty($row['lifecycle_status']) && !in_array($row['lifecycle_status'], ['draft', 'active', 'discontinued', 'blocked', 'archived'], true)) $errors[] = "Row {$line}: lifecycle_status is invalid.";
            foreach (['can_purchase', 'can_sell', 'is_stock_item'] as $flag) if (($row[$flag] ?? null) !== null && $row[$flag] !== '' && !in_array((string) $row[$flag], ['0', '1'], true)) $errors[] = "Row {$line}: {$flag} must be 0 or 1.";
            foreach (['purchase_price', 'sales_price', 'min_stock', 'max_stock', 'reorder_level', 'tax_rate', 'weight_kg', 'length_m', 'width_m', 'height_m'] as $field) {
                if (($row[$field] ?? null) !== null && $row[$field] !== '' && !is_numeric($row[$field])) $errors[] = "Row {$line}: {$field} must be numeric.";
                if (($row[$field] ?? null) !== null && $row[$field] !== '' && is_numeric($row[$field]) && (float) $row[$field] < 0) $errors[] = "Row {$line}: {$field} cannot be negative.";
            }
            if (is_numeric($row['max_stock'] ?? null) && is_numeric($row['min_stock'] ?? null) && (float) $row['max_stock'] < (float) $row['min_stock']) $errors[] = "Row {$line}: max_stock cannot be below min_stock.";
            if (is_numeric($row['tax_rate'] ?? null) && ((float) $row['tax_rate'] < 0 || (float) $row['tax_rate'] > 100)) $errors[] = "Row {$line}: tax_rate must be between 0 and 100.";
            if (!empty($row['status']) && !in_array((string) $row['status'], ['0', '1'], true)) $errors[] = "Row {$line}: status must be 0 or 1.";
            foreach (['supplier_id' => Supplier::class, 'unit_id' => Unit::class, 'category_id' => Category::class, 'brand_id' => Brand::class, 'tax_rate_id' => TaxRate::class] as $field => $model) {
                if (!empty($row[$field]) && !$this->ownedQuery($model, $companyId)->whereKey($row[$field])->exists()) $errors[] = "Row {$line}: {$field} does not exist for this company.";
            }
            if (!empty($row['id']) && !$this->ownedQuery(Product::class, $companyId)->whereKey($row['id'])->exists()) $errors[] = "Row {$line}: product id does not exist for this company.";
            if (!empty($row['sku']) && $this->ownedQuery(Product::class, $companyId)->where('sku', $row['sku'])->when($row['id'] ?? null, fn ($query) => $query->where('id', '!=', $row['id']))->exists()) $errors[] = "Row {$line}: SKU already exists.";
            if (!empty($row['barcode']) && $this->ownedQuery(Product::class, $companyId)->where('barcode', $row['barcode'])->when($row['id'] ?? null, fn ($query) => $query->where('id', '!=', $row['id']))->exists()) $errors[] = "Row {$line}: barcode already exists.";
            if (!empty($row['sku']) && in_array($row['sku'], $seenSkus, true)) $errors[] = "Row {$line}: SKU is duplicated in this file.";
            if (!empty($row['barcode']) && in_array($row['barcode'], $seenBarcodes, true)) $errors[] = "Row {$line}: barcode is duplicated in this file.";
            if (!empty($row['sku'])) $seenSkus[] = $row['sku']; if (!empty($row['barcode'])) $seenBarcodes[] = $row['barcode']; $rows[] = $row;
        }
        return compact('rows', 'errors');
    }

    public function importRows(array $rows, ?int $companyId, ?int $userId = null): int
    {
        return DB::transaction(function () use ($rows, $companyId, $userId): int {
            $imported = 0;
            foreach ($rows as $row) {
                $payload = collect($row)->only(self::FIELDS)->filter(fn ($value) => $value !== null && $value !== '')->all();
                $payload['status'] = $payload['status'] ?? 1; $payload['product_type'] = $payload['product_type'] ?? 'stock'; $payload['lifecycle_status'] = $payload['lifecycle_status'] ?? 'active'; $payload['can_purchase'] = (int) ($payload['can_purchase'] ?? 1); $payload['can_sell'] = (int) ($payload['can_sell'] ?? 1); $payload['is_stock_item'] = (int) ($payload['is_stock_item'] ?? 1);
                if (!empty($row['id'])) {
                    $product = $this->ownedQuery(Product::class, $companyId)->whereKey($row['id'])->firstOrFail(); $before = $product->only(array_keys($payload));
                    app(ProductLifecycleService::class)->assertTransitionAllowed($product, $payload); $product->update($payload + ['updated_by' => $userId]);
                    app(AuditService::class)->record('product.bulk_import.updated', $product, $before, $product->fresh()->only(array_keys($payload)) + ['bulk_import' => true], $userId);
                } else {
                    if (empty($payload['sku'])) $payload['sku'] = $this->nextSku($companyId);
                    $product = Product::create($payload + ['company_id' => $companyId, 'quantity' => 0, 'created_by' => $userId]);
                    app(AuditService::class)->record('product.bulk_import.created', $product, null, $product->toArray() + ['bulk_import' => true], $userId);
                }
                $imported++;
            }
            app(AuditService::class)->record('product.bulk_import.completed', null, null, ['rows' => $imported, 'bulk_import' => true], $userId);
            return $imported;
        });
    }

    private function ownedQuery(string $model, ?int $companyId)
    {
        return $model::withoutGlobalScopes()->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); });
    }

    private function nextSku(?int $companyId): string
    {
        do { $sku = app(NumberingSequenceService::class)->nextOrFallback('product', 'SKU-'.Str::upper(Str::random(10)), $companyId); } while ($this->ownedQuery(Product::class, $companyId)->where('sku', $sku)->exists());
        return $sku;
    }
}
