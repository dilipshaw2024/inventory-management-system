<?php

namespace App\Console\Commands;

use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Company;
use App\Services\AuditService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportOpeningStock extends Command
{
    protected $signature = 'erp:import-opening-stock {file : CSV file path} {company_id : Owning company ID} {--date= : Opening stock date, defaults to today} {--dry-run : Validate without creating a pending adjustment}';
    protected $description = 'Import opening stock CSV rows into an approval-pending inventory adjustment';

    public function handle(): int
    {
        $file = $this->argument('file');
        $companyId = (int) $this->argument('company_id');
        if (!is_file($file) || !is_readable($file)) { $this->error('CSV file does not exist or is not readable.'); return self::FAILURE; }
        if (!Company::whereKey($companyId)->exists()) { $this->error("Company {$companyId} does not exist."); return self::FAILURE; }
        $handle = fopen($file, 'rb');
        $header = fgetcsv($handle);
        if (!$header) { fclose($handle); $this->error('CSV file is empty.'); return self::FAILURE; }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        foreach (['sku', 'quantity'] as $required) if (!in_array($required, $header, true)) { fclose($handle); $this->error('CSV must include columns: sku, quantity.'); return self::FAILURE; }
        $rows = []; $errors = []; $lineNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $lineNumber++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), null));
            $sku = trim((string) ($row['sku'] ?? '')); $quantity = (float) ($row['quantity'] ?? 0);
            $product = Product::withoutGlobalScope('company')->where('sku', $sku)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->first();
            if (!$product) { $errors[] = "Line {$lineNumber}: SKU '{$sku}' was not found for company {$companyId}."; continue; }
            if ($quantity <= 0) { $errors[] = "Line {$lineNumber}: quantity must be greater than zero."; continue; }
            $locationId = !empty($row['location_id']) ? (int) $row['location_id'] : null;
            if ($locationId && !InventoryLocation::withoutGlobalScopes()->whereKey($locationId)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) { $errors[] = "Line {$lineNumber}: location does not belong to company {$companyId}."; continue; }
            if ($product->tracking_type === 'serial') {
                $serials = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) ($row['serial_numbers'] ?? '')))));
                if (count($serials) !== (int) round($quantity)) { $errors[] = "Line {$lineNumber}: serial count must equal quantity for SKU '{$sku}'."; continue; }
            }
            if (in_array($product->tracking_type, ['batch', 'lot'], true) && trim((string) ($row['batch_no'] ?? '')) === '') { $errors[] = "Line {$lineNumber}: batch_no is required for SKU '{$sku}'."; continue; }
            $rows[] = ['product_id' => $product->id, 'location_id' => $locationId, 'direction' => 'in', 'quantity' => $quantity, 'unit_cost' => ($row['unit_cost'] ?? '') !== '' ? (float) $row['unit_cost'] : null, 'batch_no' => trim((string) ($row['batch_no'] ?? '')) ?: null, 'serial_numbers' => trim((string) ($row['serial_numbers'] ?? '')) ?: null, 'manufacturing_date' => $row['manufacturing_date'] ?? null, 'expiry_date' => $row['expiry_date'] ?? null, 'best_before_date' => $row['best_before_date'] ?? null, 'warranty_until' => $row['warranty_until'] ?? null];
        }
        fclose($handle);
        if ($errors) { foreach ($errors as $error) $this->error($error); return self::FAILURE; }
        if (!$rows) { $this->error('CSV contains no valid data rows.'); return self::FAILURE; }
        $reference = 'opening-import-'.substr(hash_file('sha256', $file), 0, 32);
        $existing = InventoryAdjustment::withoutGlobalScopes()->where('company_id', $companyId)->where('external_reference', $reference)->first();
        if ($existing) { $this->info("Import already exists as adjustment {$existing->adjustment_no} ({$existing->status})."); return self::SUCCESS; }
        if ($this->option('dry-run')) { $this->info('Validated '.count($rows).' opening-stock row(s); no records created.'); return self::SUCCESS; }
        $date = $this->option('date') ?: now()->toDateString();
        try { $date = Carbon::parse($date)->toDateString(); } catch (\Throwable) { $this->error('The --date value is invalid.'); return self::FAILURE; }
        $adjustment = DB::transaction(function () use ($companyId, $reference, $date, $rows): InventoryAdjustment {
            $adjustment = InventoryAdjustment::create(['company_id' => $companyId, 'adjustment_no' => 'OPEN-IMP-'.strtoupper(substr($reference, -12)), 'external_reference' => $reference, 'date' => $date, 'reason_code' => 'opening_stock', 'description' => 'Opening stock CSV import '.$reference, 'status' => 'pending']);
            foreach ($rows as $row) InventoryAdjustmentLine::create(['adjustment_id' => $adjustment->id] + $row);
            app(AuditService::class)->record('inventory_adjustment.imported', $adjustment, null, ['rows' => count($rows), 'external_reference' => $reference]);
            return $adjustment;
        });
        $this->info("Created pending opening-stock adjustment {$adjustment->adjustment_no} with ".count($rows).' row(s). Approve it from the inventory adjustments screen.');
        return self::SUCCESS;
    }
}
