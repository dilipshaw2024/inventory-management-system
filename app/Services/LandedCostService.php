<?php

namespace App\Services;

use App\Models\GoodsReceiptLine;
use App\Models\InventoryCostLayer;
use App\Models\LandedCost;
use App\Models\InventoryCostLayerAdjustment;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\AutomaticAccountingService;
use Illuminate\Support\Facades\DB;

class LandedCostService
{
    public function approve(LandedCost $landedCost): void
    {
        DB::transaction(function () use ($landedCost): void {
            // Lock the document itself before inspecting its lifecycle. The
            // controller check is not sufficient when approval is triggered
            // concurrently by the browser, integration API, or a worker.
            $landedCost = LandedCost::whereKey($landedCost->getKey())->lockForUpdate()->firstOrFail();
            if ($landedCost->status !== 'pending') {
                throw new \RuntimeException('Only pending landed costs can be approved.');
            }

            $receipt = $landedCost->goodsReceipt()->with('lines')->lockForUpdate()->firstOrFail();
            if ($receipt->status !== 'approved') throw new \RuntimeException('Landed costs require an approved goods receipt.');
            $lines = $receipt->lines->filter(fn ($line) => (float) $line->received_qty > 0);
            $base = $landedCost->allocation_method === 'by_quantity' ? (float) $lines->sum('received_qty') : (float) $lines->sum(fn ($line) => (float) $line->received_qty * (float) $line->unit_cost);
            if ($base <= 0) throw new \RuntimeException('The receipt has no quantity/value to allocate.');
            $productAllocations = [];
            foreach ($lines as $line) {
                $weight = $landedCost->allocation_method === 'by_quantity' ? (float) $line->received_qty : (float) $line->received_qty * (float) $line->unit_cost;
                $amount = (float) $landedCost->amount * $weight / $base;
                $perUnit = $amount / max((float) $line->received_qty, 0.000001);
                $landedCost->allocations()->create(['goods_receipt_line_id' => $line->id, 'amount' => $amount, 'per_unit_amount' => $perUnit]);
                $productAllocations[$line->product_id]['amount'] = ($productAllocations[$line->product_id]['amount'] ?? 0) + $amount;
                $productAllocations[$line->product_id]['quantity'] = ($productAllocations[$line->product_id]['quantity'] ?? 0) + (float) $line->received_qty;
            }
            $adjustedAmount = 0.0;
            $consumedAdjustment = 0.0;
            foreach ($productAllocations as $productId => $allocation) {
                $perUnit = (float) $allocation['amount'] / max((float) $allocation['quantity'], 0.000001);
                $layers = InventoryCostLayer::with('consumptions')
                    ->where('product_id', $productId)
                    ->where('source_id', $receipt->id)
                    ->lockForUpdate()
                    ->get();
                foreach ($layers as $layer) {
                    $oldUnitCost = (float) $layer->unit_cost;
                    $newUnitCost = $oldUnitCost + $perUnit;
                    $layer->unit_cost = $newUnitCost;
                    $layer->save();
                    $consumedQuantity = (float) $layer->consumptions->sum('quantity');
                    $consumedAdjustment += $consumedQuantity * $perUnit;
                    foreach ($layer->consumptions as $consumption) {
                        $consumption->update([
                            'unit_cost' => $newUnitCost,
                            'total_cost' => (float) $consumption->quantity * $newUnitCost,
                        ]);
                    }
                    InventoryCostLayerAdjustment::create([
                        'landed_cost_id' => $landedCost->id, 'cost_layer_id' => $layer->id, 'product_id' => $layer->product_id,
                        'old_unit_cost' => $oldUnitCost, 'new_unit_cost' => $newUnitCost,
                        'adjustment_amount' => (float) $layer->original_quantity * $perUnit, 'adjusted_by' => auth()->id(),
                    ]);
                    $adjustedAmount += (float) $layer->original_quantity * $perUnit;
                }
            }
            if (abs($adjustedAmount - (float) $landedCost->amount) > 0.000001) {
                throw new \RuntimeException('The full landed cost cannot be allocated because the receipt cost layers are incomplete.');
            }
            $landedCost->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            app(AutomaticAccountingService::class)->postLandedCost($landedCost, (float) $landedCost->amount - $consumedAdjustment, $consumedAdjustment);
        });
    }

    public function reverse(LandedCost $landedCost, string $reason): void
    {
        if (trim($reason) === '') throw new \InvalidArgumentException('A reversal reason is required.');

        DB::transaction(function () use ($landedCost, $reason): void {
            $landedCost = LandedCost::with('layerAdjustments')->whereKey($landedCost->getKey())->lockForUpdate()->firstOrFail();
            if ($landedCost->status !== 'approved') throw new \RuntimeException('Only approved landed costs can be reversed.');
            if ($landedCost->layerAdjustments->isEmpty()) throw new \RuntimeException('This landed cost has no valuation adjustments to reverse.');

            foreach ($landedCost->layerAdjustments as $adjustment) {
                if ($adjustment->is_reversal) continue;
                $latest = InventoryCostLayerAdjustment::where('cost_layer_id', $adjustment->cost_layer_id)->latest('id')->first();
                if (!$latest || (int) $latest->id !== (int) $adjustment->id) throw new \RuntimeException('This landed cost cannot be reversed because a later valuation adjustment exists.');
                $layer = InventoryCostLayer::with('consumptions')->lockForUpdate()->findOrFail($adjustment->cost_layer_id);
                if ((float) $layer->unit_cost !== (float) $adjustment->new_unit_cost) throw new \RuntimeException('This landed cost cannot be reversed because the cost layer has changed.');
                if ($landedCost->approved_at && $layer->consumptions->contains(fn ($consumption): bool => $consumption->created_at?->gt($landedCost->approved_at))) {
                    throw new \RuntimeException('This landed cost cannot be reversed after the layer was consumed.');
                }
                $layer->update(['unit_cost' => $adjustment->old_unit_cost]);
                foreach ($layer->consumptions as $consumption) {
                    $consumption->update(['unit_cost' => $adjustment->old_unit_cost, 'total_cost' => (float) $consumption->quantity * (float) $adjustment->old_unit_cost]);
                }
                InventoryCostLayerAdjustment::create([
                    'landed_cost_id' => $landedCost->id, 'cost_layer_id' => $layer->id, 'product_id' => $layer->product_id,
                    'old_unit_cost' => $adjustment->new_unit_cost, 'new_unit_cost' => $adjustment->old_unit_cost,
                    'adjustment_amount' => -((float) $adjustment->adjustment_amount), 'adjusted_by' => auth()->id(),
                    'is_reversal' => true, 'reversal_of_id' => $adjustment->id,
                ]);
            }

            $journal = JournalEntry::where('source_type', $landedCost->getMorphClass())->where('source_id', $landedCost->id)->where('status', 'posted')->first();
            if ($journal) app(AccountingService::class)->reverse($journal, $reason);
            $landedCost->update(['status' => 'reversed', 'reversal_reason' => $reason, 'reversed_by' => auth()->id(), 'reversed_at' => now()]);
        });
    }
}
