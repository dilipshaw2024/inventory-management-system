<?php

namespace App\Console\Commands;

use App\Services\ProductImportService;
use App\Services\ProductSpreadsheetService;
use Illuminate\Console\Command;

class ImportProducts extends Command
{
    protected $signature = 'erp:products:import {file : CSV or XLSX product file path} {company_id : Owning company ID} {--dry-run : Validate without changing products}';
    protected $description = 'Validate or import products from a CSV/XLSX file for a company';

    public function handle(ProductSpreadsheetService $spreadsheet, ProductImportService $imports): int
    {
        $file = (string) $this->argument('file'); $companyId = (int) $this->argument('company_id');
        if (!is_file($file) || !is_readable($file)) { $this->error('The product import file is missing or unreadable.'); return self::FAILURE; }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt', 'xlsx'], true)) { $this->error('The product import file must be CSV, TXT, or XLSX.'); return self::FAILURE; }
        try { $result = $imports->validateRows($spreadsheet->read($file, $extension), $companyId); }
        catch (\Throwable $exception) { $this->error('Import rejected: '.$exception->getMessage()); return self::FAILURE; }
        if ($result['errors']) { $this->error(count($result['errors']).' validation error(s) found.'); foreach (array_slice($result['errors'], 0, 25) as $error) $this->line($error); return self::FAILURE; }
        $this->info(count($result['rows']).' product row(s) passed validation.');
        if ($this->option('dry-run')) return self::SUCCESS;
        try { $count = $imports->importRows($result['rows'], $companyId); }
        catch (\Throwable $exception) { $this->error('Import failed: '.$exception->getMessage()); return self::FAILURE; }
        $this->info("Imported {$count} product row(s) for company {$companyId}.");
        return self::SUCCESS;
    }
}
