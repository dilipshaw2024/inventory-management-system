<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\InventoryMovement;
use App\Models\InventoryCostConsumption;
use App\Models\InventoryReturn;
use App\Models\InventoryReconciliationSnapshot;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;

class AccountingReconciliationService
{
    public function sales(int $companyId, string $from, string $to, ?int $productId = null, ?int $customerId = null, float $tolerance = 0.01): array
    {
        $mapping = AccountMapping::where(function ($query) use ($companyId): void {
            $query->where('company_id', $companyId)->orWhereNull('company_id');
        })->where('mapping_key', 'sales_revenue')->orderByRaw('company_id IS NULL')->first();
        $account = $mapping?->account_id ? ChartOfAccount::find($mapping->account_id) : null;
        $invoices = Invoice::with(['invoice_details.product'])
            ->where('company_id', $companyId)->whereIn('status', [1, 'approved'])
            ->whereBetween('date', [$from, $to])
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->whereHas('invoice_details', fn ($query) => $query->when($productId, fn ($lines, $id) => $lines->where('product_id', $id)))
            ->get();
        $rows = collect();
        foreach ($invoices as $invoice) {
            $details = $invoice->invoice_details->when($productId, fn ($lines) => $lines->where('product_id', $productId));
            $baseTotal = (float) $invoice->invoice_details->sum(function ($line) use ($invoice): float {
                return max(0, (float) $line->selling_price - ($invoice->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0));
            });
            $discount = max(0, $baseTotal - (float) $invoice->subtotal_amount);
            foreach ($details as $line) {
                $base = max(0, (float) $line->selling_price - ($invoice->tax_mode === 'inclusive' ? (float) ($line->tax_amount ?? 0) : 0));
                $allocatedDiscount = $baseTotal > 0 ? $discount * ($base / $baseTotal) : 0;
                $revenue = max(0, $base - $allocatedDiscount);
                $key = (int) $line->product_id;
                $row = $rows->get($key, ['product_id' => $key, 'product' => $line->product, 'invoice_count' => 0, 'quantity' => 0.0, 'gross_revenue' => 0.0, 'discount' => 0.0, 'net_revenue' => 0.0]);
                $row['invoice_count'] += 1; $row['quantity'] += (float) $line->selling_qty; $row['gross_revenue'] += $base; $row['discount'] += $allocatedDiscount; $row['net_revenue'] += $revenue;
                $rows->put($key, $row);
            }
        }
        $journalCredit = 0.0; $journalDebit = 0.0;
        if ($account) {
            $journal = JournalEntry::where('company_id', $companyId)->where('status', 'posted')->whereBetween('date', [$from, $to])
                ->whereHas('lines', fn ($query) => $query->where('account_id', $account->id))
                ->with(['lines' => fn ($query) => $query->where('account_id', $account->id)])->get()->flatMap->lines;
            $journalCredit = (float) $journal->sum('credit'); $journalDebit = (float) $journal->sum('debit');
        }
        $expected = (float) $rows->sum('net_revenue'); $journalNet = $journalCredit - $journalDebit;
        $variance = $expected - $journalNet;
        return ['data' => $rows->sortByDesc('net_revenue')->values()->all(), 'summary' => ['invoice_count' => $invoices->count(), 'expected_net_revenue' => round($expected, 6), 'journal_credit' => round($journalCredit, 6), 'journal_debit' => round($journalDebit, 6), 'journal_net_revenue' => round($journalNet, 6), 'variance' => round($variance, 6), 'status' => !$account ? 'needs_mapping' : (abs($variance) > $tolerance ? 'variance' : 'reconciled'), 'product_count' => $rows->count()], 'meta' => ['from' => $from, 'to' => $to, 'product_id' => $productId, 'customer_id' => $customerId, 'tolerance' => $tolerance, 'mapping_account_id' => $account?->id]];
    }

    public function cogs(int $companyId, string $from, string $to, ?int $productId = null, ?int $locationId = null, float $tolerance = 0.01): array
    {
        $mapping = AccountMapping::where(function ($query) use ($companyId): void {
            $query->where('company_id', $companyId)->orWhereNull('company_id');
        })->where('mapping_key', 'cogs')->orderByRaw('company_id IS NULL')->first();
        $account = $mapping?->account_id ? ChartOfAccount::find($mapping->account_id) : null;

        $issueMovements = InventoryMovement::with('product:id,name,sku')
            ->where('company_id', $companyId)
            ->where('movement_type', 'issue')
            ->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('posted_at')->orderBy('id')->get();
        $returnIds = InventoryReturn::where('company_id', $companyId)->where('return_type', 'sales')->where('status', 'approved')
            ->whereBetween('date', [$from, $to])->pluck('id');
        $returnMovements = InventoryMovement::with('product:id,name,sku')->where('company_id', $companyId)
            ->where('movement_type', 'return_in')->where('reference_type', (new InventoryReturn())->getMorphClass())
            ->whereIn('reference_id', $returnIds)->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($productId, fn ($query) => $query->where('product_id', $productId))
            ->when($locationId, fn ($query) => $query->where('location_id', $locationId))
            ->orderBy('posted_at')->orderBy('id')->get();
        $movements = $issueMovements->concat($returnMovements);
        $movementIds = $movements->pluck('id');
        $consumption = InventoryCostConsumption::whereIn('movement_id', $movementIds)
            ->selectRaw('movement_id, SUM(total_cost) AS total_cost')
            ->groupBy('movement_id')->pluck('total_cost', 'movement_id');
        $rows = $movements->groupBy('product_id')->map(function ($productMovements, $id) use ($consumption): array {
            $expected = (float) $productMovements->sum(function (InventoryMovement $movement) use ($consumption): float {
                $cost = $consumption->has($movement->id)
                    ? (float) $consumption->get($movement->id)
                    : (float) $movement->quantity * (float) ($movement->unit_cost ?? 0);
                return $movement->movement_type === 'return_in' ? -$cost : $cost;
            });
            return [
                'product_id' => (int) $id,
                'product' => $productMovements->first()->product,
                'movement_count' => $productMovements->count(),
                'quantity_issued' => (float) $productMovements->where('movement_type', 'issue')->sum('quantity'),
                'quantity_returned' => (float) $productMovements->where('movement_type', 'return_in')->sum('quantity'),
                'expected_cogs' => round($expected, 6),
            ];
        })->sortBy('product_id')->values();

        $journalNet = 0.0;
        $journalDebit = 0.0;
        $journalCredit = 0.0;
        $journalEntries = collect();
        if ($account) {
            $journalEntries = JournalEntry::where('company_id', $companyId)->where('status', 'posted')
                ->whereBetween('date', [$from, $to])->whereHas('lines', fn ($query) => $query->where('account_id', $account->id))
                ->with(['lines' => fn ($query) => $query->where('account_id', $account->id)])->get();
            $journal = $journalEntries->flatMap->lines;
            $journalDebit = (float) $journal->sum('debit');
            $journalCredit = (float) $journal->sum('credit');
            $journalNet = $journalDebit - $journalCredit;
        }
        $expected = (float) $rows->sum('expected_cogs');
        $variance = $expected - $journalNet;
        $sourceReconciliation = [];
        if ($productId === null && $locationId === null) {
            $expectedBySource = $movements->groupBy(fn (InventoryMovement $movement): string => ($movement->reference_type ?: 'inventory_movement').':'.($movement->reference_id ?: $movement->id))
                ->map(function ($sourceMovements) use ($consumption): array {
                    $movement = $sourceMovements->first();
                    $amount = (float) $sourceMovements->sum(function (InventoryMovement $row) use ($consumption): float {
                        $cost = $consumption->has($row->id) ? (float) $consumption->get($row->id) : (float) $row->quantity * (float) ($row->unit_cost ?? 0);
                        return $row->movement_type === 'return_in' ? -$cost : $cost;
                    });
                    return ['source_type' => $movement->reference_type, 'source_id' => $movement->reference_id, 'expected_cogs' => round($amount, 6)];
                });
            $postedBySource = $journalEntries->groupBy(fn (JournalEntry $entry): string => ($entry->source_type ?: 'unlinked_journal').':'.($entry->source_id ?: $entry->id))
                ->map(function ($entries) use ($account): float {
                    return (float) $entries->sum(fn (JournalEntry $entry): float => (float) $entry->lines->where('account_id', $account->id)->sum('debit') - (float) $entry->lines->where('account_id', $account->id)->sum('credit'));
                });
            $journalSourceDetails = $journalEntries->mapWithKeys(fn (JournalEntry $entry): array => [($entry->source_type ?: 'unlinked_journal').':'.($entry->source_id ?: $entry->id) => ['source_type' => $entry->source_type, 'source_id' => $entry->source_id]])
                ->all();
            $sourceKeys = $expectedBySource->keys()->merge($postedBySource->keys())->unique()->sort()->values();
            $sourceReconciliation = $sourceKeys->map(function (string $key) use ($expectedBySource, $postedBySource, $journalSourceDetails, $tolerance): array {
                $expectedSource = $expectedBySource->get($key, ($journalSourceDetails[$key] ?? []) + ['expected_cogs' => 0]);
                $posted = (float) $postedBySource->get($key, 0);
                $expectedValue = (float) $expectedSource['expected_cogs'];
                return ['source_type' => $expectedSource['source_type'] ?? null, 'source_id' => $expectedSource['source_id'] ?? null, 'expected_cogs' => round($expectedValue, 6), 'journal_cogs' => round($posted, 6), 'variance' => round($expectedValue - $posted, 6), 'status' => abs($expectedValue - $posted) > $tolerance ? 'variance' : 'reconciled'];
            })->values()->all();
        }
        return [
            'data' => $rows->all(),
            'summary' => [
                'expected_cogs' => round($expected, 6),
                'journal_debit' => round($journalDebit, 6),
                'journal_credit' => round($journalCredit, 6),
                'journal_net' => round($journalNet, 6),
                'variance' => round($variance, 6),
                'status' => !$account ? 'needs_mapping' : (abs($variance) > $tolerance ? 'variance' : 'reconciled'),
                'movement_count' => $movements->count(),
                'product_count' => $rows->count(),
            ],
            'source_reconciliation' => $sourceReconciliation,
            'meta' => ['from' => $from, 'to' => $to, 'product_id' => $productId, 'location_id' => $locationId, 'tolerance' => $tolerance, 'mapping_account_id' => $account?->id, 'source_level_matching' => $productId === null && $locationId === null],
        ];
    }

    public function rows(int $companyId, string $to): array
    {
        $mapping = AccountMapping::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->orderByRaw('company_id IS NULL')->get()->unique('mapping_key')->keyBy('mapping_key');
        $accountIds = $mapping->pluck('account_id')->filter()->values();
        $accountTypes = ChartOfAccount::whereIn('id', $accountIds)->pluck('account_type', 'id');
        $journalBalances = JournalEntry::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where('status', 'posted')->whereDate('date', '<=', $to)
            ->with(['lines' => fn ($query) => $query->whereIn('account_id', $accountIds)])
            ->get()->flatMap->lines->groupBy('account_id')->map(fn ($lines): float => (float) $lines->sum('debit') - (float) $lines->sum('credit'));
        $scope = fn ($query) => $query->where(fn ($company) => $company->where('company_id', $companyId)->orWhereNull('company_id'));
        $ap = (float) PurchaseInvoice::where($scope)->where('status', 'approved')->whereDate('invoice_date', '<=', $to)->sum('total_amount')
            - (float) SupplierPayment::where($scope)->where('status', 'approved')->where('is_reversed', false)->whereDate('payment_date', '<=', $to)->whereNotNull('purchase_invoice_id')->sum('amount')
            - (float) SupplierPaymentAllocation::where($scope)->whereNull('voided_at')->whereHas('payment', fn ($query) => $query->where('status', 'approved')->where('is_reversed', false))->whereDate('allocated_at', '<=', $to)->sum('amount');
        $ar = (float) Invoice::where($scope)->where('status', 1)->whereDate('date', '<=', $to)->sum('total_amount')
            - (float) Payment::where($scope)->where('approval_status', 'approved')->where('is_reversed', false)->where(fn ($query) => $query->whereDate('payment_date', '<=', $to)->orWhere(fn ($legacy) => $legacy->whereNull('payment_date')->whereDate('created_at', '<=', $to)))->sum('paid_amount');
        $snapshot = InventoryReconciliationSnapshot::where('company_id', $companyId)->whereDate('as_of_date', $to)->first();
        $inventory = $snapshot
            ? (float) collect($snapshot->rows ?? [])->sum(fn (array $row): float => (float) ($row['balance_value'] ?? 0))
            : (float) InventoryMovement::where('company_id', $companyId)->whereDate('posted_at', '<=', $to)->get()->sum(function (InventoryMovement $movement): float {
                $inbound = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
                $outbound = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
                $sign = in_array($movement->movement_type, $inbound, true) ? 1 : (in_array($movement->movement_type, $outbound, true) ? -1 : 0);
                return $sign * (float) $movement->quantity * (float) ($movement->unit_cost ?? 0);
            });
        return collect([
            ['name' => 'Accounts payable', 'mapping' => 'accounts_payable', 'subledger' => $ap],
            ['name' => 'Accounts receivable', 'mapping' => 'accounts_receivable', 'subledger' => $ar],
            ['name' => 'Inventory', 'mapping' => 'inventory', 'subledger' => $inventory],
        ])->map(function (array $row) use ($mapping, $journalBalances, $accountTypes): array {
            $row['account'] = $mapping->get($row['mapping'])?->account_id;
            $raw = $row['account'] ? (float) $journalBalances->get($row['account'], 0) : null;
            $row['journal'] = $raw === null ? null : (in_array($accountTypes->get($row['account']), ['liability', 'income'], true) ? -$raw : $raw);
            $row['variance'] = $row['journal'] === null ? null : $row['subledger'] - $row['journal'];
            $row['status'] = $row['journal'] === null ? 'needs_mapping' : (abs((float) $row['variance']) >= 0.01 ? 'variance' : 'reconciled');
            return $row;
        })->all();
    }
}
