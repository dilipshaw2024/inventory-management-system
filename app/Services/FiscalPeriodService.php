<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\FiscalPeriod;
use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\InventoryCostRevaluationRun;
use Carbon\Carbon;

class FiscalPeriodService
{
    public function assertOpen(?int $companyId, string $date, string $message = 'No open fiscal period exists for this transaction date.'): void
    {
        if (!$companyId) return;
        $year = FiscalYear::where('company_id', $companyId)->where('status', 'open')
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
        if (!$year) {
            if (FiscalYear::where('company_id', $companyId)->exists()) throw new \RuntimeException($message);
            return;
        }
        if (FiscalPeriod::where('company_id', $companyId)->where('fiscal_year_id', $year->id)->exists()
            && !FiscalPeriod::where('company_id', $companyId)->where('fiscal_year_id', $year->id)->where('status', 'open')
                ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->exists()) {
            throw new \RuntimeException($message);
        }
    }

    public function assertOpenForReference(?int $companyId, ?\Illuminate\Database\Eloquent\Model $reference = null, ?string $date = null): void
    {
        $value = $date ?: ($reference?->getAttribute('date') ?: $reference?->getAttribute('invoice_date') ?: $reference?->getAttribute('receipt_date') ?: $reference?->getAttribute('delivery_date') ?: $reference?->getAttribute('order_date') ?: $reference?->getAttribute('payment_date') ?: now()->toDateString());
        $this->assertOpen($companyId, Carbon::parse($value)->toDateString());
    }

    public function assertBankReconciliationsClosed(int $companyId, string $endDate): void
    {
        $scope = fn ($query) => $query->where(fn ($tenant) => $tenant->where('company_id', $companyId)->orWhereNull('company_id'));
        $unmatched = $scope(BankStatementLine::query())
            ->whereDate('transaction_date', '<=', $endDate)
            ->where('status', 'unmatched')->count();
        if ($unmatched > 0) throw new \RuntimeException('Close checklist failed: '.$unmatched.' bank statement lines remain unmatched through '.$endDate.'.');

        $draft = $scope(BankReconciliation::query())
            ->whereDate('statement_date', '<=', $endDate)
            ->where('status', 'draft')->count();
        if ($draft > 0) throw new \RuntimeException('Close checklist failed: '.$draft.' bank reconciliations remain open through '.$endDate.'.');
    }

    public function assertStandardCostVariancesClosed(int $companyId, string $endDate): void
    {
        $movements = \App\Models\InventoryMovement::withoutGlobalScopes()
            ->with(['product', 'allocations'])
            ->where('company_id', $companyId)
            ->whereDate('posted_at', '<=', $endDate)
            ->whereIn('movement_type', ['receipt', 'opening', 'transfer_in', 'adjustment_in', 'return_in', 'issue', 'transfer_out', 'adjustment_out', 'scrap', 'return_out'])
            ->get();
        if ($movements->isEmpty()) return;

        $policyService = app(\App\Services\ProductCostingPolicyService::class);
        $outstanding = 0;
        foreach ($movements as $movement) {
            if (!$movement->product) continue;
            $policy = $policyService->resolve($movement->product, $movement->posted_at ?: now());
            if (($policy['costing_method'] ?? null) !== 'standard' || $policy['standard_cost'] === null) continue;
            $quantity = abs((float) $movement->quantity);
            if ($quantity <= 0) continue;
            $inbound = in_array((string) $movement->movement_type, ['receipt', 'opening', 'transfer_in', 'adjustment_in', 'return_in'], true);
            $actual = $inbound
                ? $quantity * (float) ($movement->unit_cost ?? 0)
                : (float) $movement->allocations->sum(fn ($allocation): float => (float) $allocation->total_cost);
            if (!$inbound && $actual <= 0) $actual = $quantity * (float) ($movement->unit_cost ?? 0);
            $variance = round($actual - ($quantity * (float) $policy['standard_cost']), 6);
            if (abs($variance) <= 0.000001) continue;
            $reference = 'STD-VARIANCE-MOVEMENT-'.$movement->id;
            if (!\App\Models\JournalEntry::where('company_id', $companyId)->where('external_reference', $reference)->exists()) $outstanding++;
        }
        if ($outstanding > 0) {
            throw new \RuntimeException('Close checklist failed: '.$outstanding.' standard-cost variance journal(s) remain unposted through '.$endDate.'.');
        }
    }

    public function assertCostRevaluationsClosed(int $companyId, string $endDate): void
    {
        $pending = InventoryCostRevaluationRun::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('status', 'pending')->whereDate('as_of_date', '<=', $endDate)->count();
        if ($pending > 0) throw new \RuntimeException('Close checklist failed: '.$pending.' inventory cost revaluation run(s) remain pending through '.$endDate.'.');
    }
}
