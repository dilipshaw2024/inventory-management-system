<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ApprovalPolicy;
use App\Models\Supplier;
use App\Models\SupplierProductPrice;
use Illuminate\Support\Facades\DB;

class SupplierProductPriceImportService
{
    public const HEADERS = ['external_reference', 'supplier_id', 'product_id', 'minimum_quantity', 'unit_price', 'currency_code', 'starts_on', 'ends_on', 'supplier_sku', 'lead_time_days'];

    /** @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>} */
    public function validateRows(array $importRows, ?int $companyId): array
    {
        $headers = array_keys($importRows[0] ?? []);
        $required = ['supplier_id', 'product_id', 'minimum_quantity', 'unit_price', 'currency_code'];
        $errors = [];
        $rows = [];
        foreach ($required as $field) if (!in_array($field, $headers, true)) $errors[] = "Header {$field} is required.";
        if (count($headers) !== count(array_unique($headers))) $errors[] = 'Import headers must be unique.';
        $seenReferences = [];
        foreach ($importRows as $index => $row) {
            $line = $index + 2;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) continue;
            foreach ($required as $field) if (blank($row[$field] ?? null)) $errors[] = "Row {$line}: {$field} is required.";
            foreach (['minimum_quantity', 'unit_price'] as $field) {
                if (!is_numeric($row[$field] ?? null) || (float) $row[$field] < 0 || ($field === 'minimum_quantity' && (float) $row[$field] <= 0)) $errors[] = "Row {$line}: {$field} must be a valid non-negative number.";
            }
            $currency = strtoupper(trim((string) ($row['currency_code'] ?? '')));
            if (!preg_match('/^[A-Z]{3}$/', $currency)) $errors[] = "Row {$line}: currency_code must be a three-letter code.";
            if (($row['lead_time_days'] ?? '') !== '' && (!is_numeric($row['lead_time_days']) || (int) $row['lead_time_days'] < 0)) $errors[] = "Row {$line}: lead_time_days must be a non-negative integer.";
            foreach (['starts_on', 'ends_on'] as $field) if (($row[$field] ?? '') !== '' && strtotime((string) $row[$field]) === false) $errors[] = "Row {$line}: {$field} must be a valid date.";
            if (!empty($row['starts_on']) && !empty($row['ends_on']) && strtotime((string) $row['ends_on']) < strtotime((string) $row['starts_on'])) $errors[] = "Row {$line}: ends_on cannot precede starts_on.";
            if (!$this->owned(Supplier::class, $companyId)->whereKey($row['supplier_id'] ?? 0)->where('is_active', true)->exists()) $errors[] = "Row {$line}: supplier_id is not an active supplier for this company.";
            if (!$this->owned(Product::class, $companyId)->whereKey($row['product_id'] ?? 0)->exists()) $errors[] = "Row {$line}: product_id does not exist for this company.";
            $reference = trim((string) ($row['external_reference'] ?? '')) ?: null;
            if ($reference && in_array($reference, $seenReferences, true)) $errors[] = "Row {$line}: external_reference is duplicated in this file.";
            if ($reference) $seenReferences[] = $reference;
            $rows[] = array_merge($row, ['currency_code' => $currency, 'external_reference' => $reference]);
        }
        return compact('rows', 'errors');
    }

    public function importRows(array $rows, ?int $companyId, ?int $userId = null): int
    {
        return DB::transaction(function () use ($rows, $companyId, $userId): int {
            $imported = 0;
            foreach ($rows as $row) {
                $payload = collect($row)->only(self::HEADERS)->filter(fn ($value) => $value !== null && $value !== '')->all();
                $payload['currency_code'] = strtoupper((string) $payload['currency_code']);
                $payload['minimum_quantity'] = (float) $payload['minimum_quantity'];
                $payload['unit_price'] = (float) $payload['unit_price'];
                if (isset($payload['lead_time_days'])) $payload['lead_time_days'] = (int) $payload['lead_time_days'];
                $existing = !empty($payload['external_reference']) ? SupplierProductPrice::withoutGlobalScopes()->where('company_id', $companyId)->where('external_reference', $payload['external_reference'])->lockForUpdate()->first() : null;
                if ($existing) {
                    $before = $existing->only(array_keys($payload));
                    $approval = $this->requiresApproval($companyId) && ($existing->approval_status ?? 'approved') === 'approved'
                        ? ['approval_status' => 'pending', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => null, 'rejected_by' => null, 'rejected_at' => null]
                        : [];
                    $existing->update($payload + $approval);
                    app(AuditService::class)->record('supplier_product_price.bulk_import.updated', $existing, $before, $existing->fresh()->only(array_keys($payload)) + ['bulk_import' => true], $userId);
                } else {
                    $price = SupplierProductPrice::create($payload + ['company_id' => $companyId, 'is_active' => true, 'approval_status' => $this->requiresApproval($companyId) ? 'pending' : 'approved', 'created_by' => $userId]);
                    app(AuditService::class)->record('supplier_product_price.bulk_import.created', $price, null, $price->toArray() + ['bulk_import' => true], $userId);
                }
                $imported++;
            }
            app(AuditService::class)->record('supplier_product_price.bulk_import.completed', null, null, ['rows' => $imported, 'bulk_import' => true], $userId);
            return $imported;
        });
    }

    private function owned(string $model, ?int $companyId)
    {
        return $model::withoutGlobalScopes()->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    private function requiresApproval(?int $companyId): bool
    {
        return ApprovalPolicy::withoutGlobalScopes()->where('company_id', $companyId)->where('document_type', SupplierProductPrice::class)->where('is_active', true)->exists();
    }
}
