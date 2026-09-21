<?php

namespace App\Services;

use App\Models\InventoryCostLayer;
use App\Models\InventoryCostRevaluationLine;
use App\Models\InventoryCostRevaluationRun;
use Illuminate\Support\Facades\DB;

class InventoryCostRevaluationService
{
    public function create(int $companyId, string $asOf, ?string $externalReference, ?int $productId, ?int $locationId, ?int $requestedBy = null): InventoryCostRevaluationRun
    {
        return DB::transaction(function () use ($companyId, $asOf, $externalReference, $productId, $locationId, $requestedBy): InventoryCostRevaluationRun {
            if ($externalReference) {
                $existing = InventoryCostRevaluationRun::withoutGlobalScopes()->where('company_id', $companyId)->where('external_reference', $externalReference)->first();
                if ($existing) return $existing->load('lines');
            }
            $preview = app(InventoryCostingService::class)->revaluationPreviewForCompany($companyId, $asOf, $productId, $locationId);
            $lines = $preview->flatMap(fn (array $row) => collect($row['lines'])->map(fn (array $line): array => $line + ['product_id' => $row['product_id']]));
            if ($lines->isEmpty()) throw new \RuntimeException('No revaluation variance was found for the selected scope.');
            $run = InventoryCostRevaluationRun::create(['company_id' => $companyId, 'external_reference' => $externalReference, 'as_of_date' => $asOf, 'status' => 'pending', 'total_variance' => (float) $lines->sum('variance_amount'), 'requested_by' => $requestedBy]);
            foreach ($lines as $line) {
                InventoryCostRevaluationLine::create(['revaluation_run_id' => $run->id, 'product_id' => $line['product_id'], 'cost_layer_id' => $line['layer_id'], 'location_id' => $line['location_id'], 'quantity' => $line['quantity'], 'old_unit_cost' => $line['current_unit_cost'], 'new_unit_cost' => $line['target_unit_cost'], 'variance_amount' => $line['variance_amount']]);
            }
            app(AuditService::class)->record('inventory_cost_revaluation.created', $run, null, $run->load('lines')->toArray());
            return $run->load('lines');
        });
    }

    public function approve(int $companyId, int $runId, int $approvedBy): InventoryCostRevaluationRun
    {
        return DB::transaction(function () use ($companyId, $runId, $approvedBy): InventoryCostRevaluationRun {
            $run = InventoryCostRevaluationRun::withoutGlobalScopes()->with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'pending') throw new \RuntimeException('Only pending revaluation runs can be approved.');
            if ($run->requested_by && (int) $run->requested_by === $approvedBy) throw new \RuntimeException('The revaluation creator cannot approve the same run.');
            if ($run->as_of_date->toDateString() !== now()->toDateString()) throw new \RuntimeException('Only a revaluation run dated today can be approved.');
            foreach ($run->lines as $line) {
                $layer = InventoryCostLayer::withoutGlobalScopes()->lockForUpdate()->findOrFail($line->cost_layer_id);
                if ((int) $layer->product_id !== (int) $line->product_id || abs((float) $layer->unit_cost - (float) $line->old_unit_cost) > 0.000001) {
                    throw new \RuntimeException('The revaluation is stale because a cost layer changed after preview.');
                }
                $layer->update(['unit_cost' => $line->new_unit_cost]);
            }
            $journal = app(AutomaticAccountingService::class)->postInventoryRevaluation($run);
            $before = $run->only(['status', 'approved_by', 'approved_at', 'journal_entry_id', 'accounting_status']);
            $run->update(['status' => 'approved', 'approved_by' => $approvedBy, 'approved_at' => now(), 'journal_entry_id' => $journal?->id, 'accounting_status' => $journal ? 'posted' : 'missing_mapping']);
            app(AuditService::class)->record('inventory_cost_revaluation.approved', $run, $before, $run->fresh()->toArray());
            return $run->fresh('lines');
        });
    }

    public function reject(int $companyId, int $runId, int $rejectedBy, string $reason): InventoryCostRevaluationRun
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('A rejection reason is required.');
        return DB::transaction(function () use ($companyId, $runId, $rejectedBy, $reason): InventoryCostRevaluationRun {
            $run = InventoryCostRevaluationRun::withoutGlobalScopes()->where('company_id', $companyId)->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'pending') throw new \RuntimeException('Only pending revaluation runs can be rejected.');
            if ($run->requested_by && (int) $run->requested_by === $rejectedBy) throw new \RuntimeException('The revaluation creator cannot reject the same run.');
            $before = $run->only(['status', 'rejected_by', 'rejected_at', 'rejection_reason']);
            $run->update(['status' => 'rejected', 'rejected_by' => $rejectedBy, 'rejected_at' => now(), 'rejection_reason' => $reason]);
            app(AuditService::class)->record('inventory_cost_revaluation.rejected', $run, $before, $run->fresh()->toArray());
            return $run->fresh('lines');
        });
    }

    public function reverse(int $companyId, int $runId, int $reversedBy, string $reason): InventoryCostRevaluationRun
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('A reversal reason is required.');
        return DB::transaction(function () use ($companyId, $runId, $reversedBy, $reason): InventoryCostRevaluationRun {
            $run = InventoryCostRevaluationRun::withoutGlobalScopes()->with('lines')->where('company_id', $companyId)->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'approved') throw new \RuntimeException('Only approved revaluation runs can be reversed.');
            if ($run->approved_by && (int) $run->approved_by === $reversedBy) throw new \RuntimeException('The revaluation approver cannot reverse the same run.');
            foreach ($run->lines as $line) {
                $layer = InventoryCostLayer::withoutGlobalScopes()->lockForUpdate()->findOrFail($line->cost_layer_id);
                if ((int) $layer->product_id !== (int) $line->product_id || abs((float) $layer->unit_cost - (float) $line->new_unit_cost) > 0.000001) {
                    throw new \RuntimeException('The revaluation cannot be reversed because a cost layer changed after approval.');
                }
                $layer->update(['unit_cost' => $line->old_unit_cost]);
            }
            $reversalJournal = null;
            if ($run->journal_entry_id) {
                $journal = \App\Models\JournalEntry::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($run->journal_entry_id);
                $reversalJournal = app(AccountingService::class)->reverse($journal, $reason);
            }
            $before = $run->only(['status', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_journal_entry_id']);
            $run->update(['status' => 'reversed', 'reversed_by' => $reversedBy, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_journal_entry_id' => $reversalJournal?->id]);
            app(AuditService::class)->record('inventory_cost_revaluation.reversed', $run, $before, $run->fresh()->toArray());
            return $run->fresh('lines');
        });
    }
}
