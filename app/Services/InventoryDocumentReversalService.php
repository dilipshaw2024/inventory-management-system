<?php

namespace App\Services;

use App\Models\InventoryDocument;
use App\Models\InventoryDocumentLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryDocumentReversalService
{
    public function createMaterialIssueReversal(InventoryDocument $source, string $reason, ?User $actor = null, ?string $externalReference = null): InventoryDocument
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('A reversal reason is required.');
        if (!$source->production_order_id) throw new \RuntimeException('Only production-linked material issues can use this reversal workflow.');
        return $this->createIssueReversal($source, $reason, $actor, $externalReference);
    }

    public function createIssueReversal(InventoryDocument $source, string $reason, ?User $actor = null, ?string $externalReference = null): InventoryDocument
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('A reversal reason is required.');
        return DB::transaction(function () use ($source, $reason, $actor, $externalReference): InventoryDocument {
            $source = InventoryDocument::with('lines')->where('company_id', $source->company_id)->lockForUpdate()->findOrFail($source->id);
            if ($source->document_type !== 'issue' || $source->status !== 'approved') {
                throw new \RuntimeException('Only approved stock issue documents can be reversed.');
            }
            if ($source->reversal()->exists()) throw new \RuntimeException('This material issue already has a reversal document.');
            app(FiscalPeriodService::class)->assertOpen($source->company_id, now()->toDateString(), 'A reversal cannot be created because the current fiscal period is closed.');

            $reversal = InventoryDocument::create([
                'company_id' => $source->company_id,
                'external_reference' => $externalReference,
                'document_no' => substr('REV-'.$source->document_no.'-'.now()->format('YmdHis'), 0, 100),
                'document_type' => 'receipt',
                'location_id' => $source->location_id,
                'date' => now()->toDateString(),
                'description' => 'Reversal of '.$source->document_no.': '.$reason,
                'status' => 'pending',
                'created_by' => $actor?->id,
                'reversal_of_id' => $source->id,
                'reversed_by' => $actor?->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);
            foreach ($source->lines as $line) {
                $allocations = collect($line->batch_allocations ?: []);
                if ($allocations->isEmpty()) {
                    $this->copyLine($reversal, $line, $line->batch_no, $line->serial_numbers);
                    continue;
                }
                foreach ($allocations as $allocation) {
                    $serials = !empty($allocation['serial_numbers']) ? implode(',', array_map('strval', $allocation['serial_numbers'])) : null;
                    $this->copyLine($reversal, $line, $allocation['batch_no'] ?? null, $serials, (float) ($allocation['quantity'] ?? 0));
                }
            }
            app(AuditService::class)->record('inventory_document.reversal_created', $reversal, null, ['source_document_id' => $source->id, 'reason' => $reason, 'actor_id' => $actor?->id]);
            return $reversal->fresh(['location', 'productionOrder', 'reversal', 'lines.product']);
        });
    }

    private function copyLine(InventoryDocument $reversal, InventoryDocumentLine $source, ?string $batchNo, ?string $serialNumbers, ?float $quantity = null): void
    {
        InventoryDocumentLine::create([
            'inventory_document_id' => $reversal->id,
            'product_id' => $source->product_id,
            'quantity' => $quantity ?? (float) $source->quantity,
            'unit_cost' => $source->unit_cost,
            'department_id' => $source->department_id,
            'cost_center_id' => $source->cost_center_id,
            'batch_no' => $batchNo,
            'serial_numbers' => $serialNumbers,
            'manufacturing_date' => $source->manufacturing_date,
            'expiry_date' => $source->expiry_date,
            'best_before_date' => $source->best_before_date,
            'warranty_until' => $source->warranty_until,
        ]);
    }
}
