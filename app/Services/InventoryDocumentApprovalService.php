<?php

namespace App\Services;

use App\Models\InventoryBatch;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryDocumentApprovalService
{
    public function approve(InventoryDocument $source, ?User $actor = null): InventoryDocument
    {
        $actorId = $actor?->id;
        return DB::transaction(function () use ($source, $actorId): InventoryDocument {
            $document = InventoryDocument::with('lines')
                ->where('company_id', $source->company_id)
                ->lockForUpdate()->findOrFail($source->id);
            if ($document->status !== 'pending') throw new \RuntimeException('This inventory document has already been processed.');
            if ($document->document_type === 'receipt' && $document->inspection_status !== 'not_required' && $document->inspection_status !== 'passed') {
                throw new \RuntimeException('This receipt must pass inspection before stock can be posted.');
            }
            app(ApprovalGuard::class)->assertDifferent($document);

            foreach ($document->lines as $line) {
                $product = Product::where(function ($query) use ($document): void {
                    $query->where('company_id', $document->company_id)->orWhereNull('company_id');
                })->lockForUpdate()->findOrFail($line->product_id);
                $quantity = (float) $line->quantity;
                if ($document->document_type === 'issue' && app(InventoryAvailabilityService::class)->available($product, false, $document->location_id, $document->company_id) < $quantity) {
                    throw new \RuntimeException('Insufficient available stock at the selected location for '.$product->name.'.');
                }

                $batch = null;
                if ($line->batch_no && $document->document_type === 'receipt') {
                    $batch = InventoryBatch::firstOrCreate(
                        ['product_id' => $product->id, 'batch_no' => $line->batch_no],
                        ['location_id' => $document->location_id, 'manufacturing_date' => $line->manufacturing_date, 'expiry_date' => $line->expiry_date, 'best_before_date' => $line->best_before_date, 'warranty_until' => $line->warranty_until]
                    );
                } elseif ($line->batch_no && $document->document_type === 'issue') {
                    $batch = InventoryBatch::where('product_id', $product->id)->where('batch_no', $line->batch_no)->lockForUpdate()->first();
                    if (!$batch) throw new \RuntimeException('Batch '.$line->batch_no.' was not found for '.$product->name.'.');
                    if ($batch->location_id && $document->location_id && (int) $batch->location_id !== (int) $document->location_id) throw new \RuntimeException('Selected batch is not held at the selected location.');
                }

                $serialNumbers = $line->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $line->serial_numbers)))) : [];
                $issuedSerials = collect();
                $receivedSerials = collect();
                if ($product->tracking_type === 'serial') {
                    if ($document->document_type === 'receipt') {
                        if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Serial count must equal quantity for '.$product->name.'.');
                        foreach ($serialNumbers as $serialNo) $receivedSerials->push(app(SerialLifecycleService::class)->receive($product, $serialNo, $document->location_id, $batch?->id, $line->warranty_until));
                    } elseif ($serialNumbers) {
                        if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Selected serial count must equal quantity for '.$product->name.'.');
                        $issuedSerials = app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $document->location_id, $batch?->id);
                    } else {
                        $issuedSerials = app(SerialLifecycleService::class)->issue($product, $quantity, $document->location_id, $batch?->id);
                    }
                }

                $product->quantity = (float) $product->quantity + ($document->document_type === 'receipt' ? $quantity : -$quantity);
                $product->save();
                $unitCost = $line->unit_cost !== null ? (float) $line->unit_cost : (float) ($product->purchase_price ?? 0);
                $departmentId = $line->department_id ?: $document->department_id;
                $costCenterId = $line->cost_center_id ?: $document->cost_center_id;
                if ($document->document_type === 'issue' && $issuedSerials->isNotEmpty()) {
                    foreach ($issuedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'issue', 1, $unitCost, $document->location_id, $document, $document->description, null, $batch?->id, $serial->id, $departmentId, $costCenterId);
                } elseif ($document->document_type === 'receipt' && $receivedSerials->isNotEmpty()) {
                    foreach ($receivedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'receipt', 1, $unitCost, $document->location_id, $document, $document->description, null, $batch?->id, $serial->id, $departmentId, $costCenterId);
                } else {
                    app(InventoryLedgerService::class)->post($product->id, $document->document_type, $quantity, $unitCost, $document->location_id, $document, $document->description, null, $batch?->id, null, $departmentId, $costCenterId);
                }
            }
            $document->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
            app(AuditService::class)->record('inventory_document.approved', $document, ['status' => 'pending'], ['status' => 'approved']);
            return $document->fresh(['location', 'department', 'costCenter', 'lines.product']);
        });
    }

    public function reject(InventoryDocument $source, string $reason, ?User $actor = null): InventoryDocument
    {
        return DB::transaction(function () use ($source, $reason, $actor): InventoryDocument {
            $document = InventoryDocument::where('company_id', $source->company_id)->lockForUpdate()->findOrFail($source->id);
            if ($document->status !== 'pending') throw new \RuntimeException('This inventory document has already been processed.');
            $before = $document->only(['status', 'inspection_notes']);
            $document->update(['status' => 'rejected', 'inspection_notes' => $reason, 'approved_by' => $actor?->id, 'approved_at' => now()]);
            app(AuditService::class)->record('inventory_document.rejected', $document, $before, $document->only(['status', 'inspection_notes', 'approved_by', 'approved_at']));
            return $document->fresh(['location', 'department', 'costCenter', 'lines.product']);
        });
    }

    public function inspect(InventoryDocument $source, string $status, string $notes, ?User $actor = null): InventoryDocument
    {
        return DB::transaction(function () use ($source, $status, $notes, $actor): InventoryDocument {
            $document = InventoryDocument::where('company_id', $source->company_id)->lockForUpdate()->findOrFail($source->id);
            if ($document->document_type !== 'receipt' || $document->status !== 'pending' || $document->inspection_status !== 'pending') throw new \RuntimeException('Only pending stock receipts can be inspected.');
            $before = $document->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
            $document->update(['inspection_status' => $status, 'inspection_notes' => $notes, 'inspected_by' => $actor?->id, 'inspected_at' => now()]);
            app(AuditService::class)->record('inventory_document.inspected', $document, $before, $document->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']));
            return $document->fresh(['location', 'department', 'costCenter', 'lines.product']);
        });
    }
}
