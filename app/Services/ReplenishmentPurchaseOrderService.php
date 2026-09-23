<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReplenishmentPurchaseOrderService
{
    /**
     * Create one approval-pending purchase order from the current live
     * replenishment suggestion. The product and open PO lines are locked
     * before the final quantity is calculated to avoid duplicate ordering.
     *
     * @return array{order: PurchaseOrder, duplicate: bool}
     */
    public function createDraft(
        int $companyId,
        int $productId,
        ?int $locationId = null,
        ?float $requestedQuantity = null,
        ?string $externalReference = null,
        ?int $createdBy = null,
        ?string $description = null
    ): array {
        if ($externalReference !== null) {
            $existing = PurchaseOrder::where('company_id', $companyId)
                ->where('external_reference', $externalReference)
                ->first();
            if ($existing) return ['order' => $existing, 'duplicate' => true];
        }

        $planning = app(ReplenishmentPlanningService::class);
        $proposal = $planning->proposalsForCompany($companyId, $productId, $locationId, null, true)->first();
        if (!$proposal) throw new \RuntimeException('No current purchase replenishment suggestion exists for this product.');

        $suggestedQuantity = (float) $proposal['quantity'];
        $quantity = $requestedQuantity === null ? $suggestedQuantity : $requestedQuantity;
        if ($quantity <= 0.000001 || $quantity > $suggestedQuantity + 0.000001) {
            throw new \RuntimeException('The requested quantity exceeds the current replenishment suggestion.');
        }

        return DB::transaction(function () use ($companyId, $productId, $locationId, $quantity, $externalReference, $createdBy, $description, $proposal): array {
            if ($externalReference !== null) {
                $existing = PurchaseOrder::where('company_id', $companyId)
                    ->where('external_reference', $externalReference)
                    ->lockForUpdate()
                    ->first();
                if ($existing) return ['order' => $existing, 'duplicate' => true];
            }

            $product = Product::withoutGlobalScope('company')
                ->whereKey($productId)
                ->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))
                ->lockForUpdate()
                ->first();
            if (!$product || !$product->supplier_id) throw new \RuntimeException('The replenishment product or supplier is not authorized.');

            $openQuantity = (float) PurchaseOrderLine::where('product_id', $productId)
                ->whereHas('purchaseOrder', fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['draft', 'submitted', 'approved', 'partially_received']))
                ->when($locationId !== null, fn ($query) => $query->where(fn ($locationQuery) => $locationQuery->whereNull('location_id')->orWhere('location_id', $locationId)))
                ->lockForUpdate()
                ->get()
                ->sum(fn (PurchaseOrderLine $line): float => max(0, (float) $line->ordered_qty - (float) $line->received_qty));
            $liveAvailable = max(0, (float) $proposal['quantity'] - $openQuantity);
            if ($quantity > $liveAvailable + 0.000001) {
                throw new \RuntimeException('The replenishment suggestion is no longer available at the requested quantity.');
            }

            $supplier = $product->supplier;
            $planningDays = (int) ($proposal['planning_days'] ?? 0);
            $expectedDate = app(PlanningCalendarService::class)
                ->addWorkingDaysForSupplier(now()->toDateString(), $planningDays, $supplier, $companyId)
                ->toDateString();
            $order = PurchaseOrder::create([
                'company_id' => $companyId,
                'supplier_id' => $product->supplier_id,
                'external_reference' => $externalReference,
                'date' => now()->toDateString(),
                'expected_date' => $expectedDate,
                'description' => $description ?: 'Created from replenishment suggestion; approval required.',
                'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId),
                'created_by' => $createdBy,
                'status' => 'draft',
            ]);
            PurchaseOrderLine::create([
                'purchase_order_id' => $order->id,
                'product_id' => $product->id,
                'location_id' => $locationId,
                'ordered_qty' => $quantity,
                'unit_price' => (float) ($proposal['unit_price'] ?? $product->purchase_price ?? 0),
            ]);
            app(AuditService::class)->record('purchase_order.created_from_replenishment_suggestion', $order, null, $order->toArray() + [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'location_id' => $locationId,
                'suggested_quantity' => $proposal['quantity'],
                'api' => true,
            ], $createdBy);

            return ['order' => $order->load(['supplier', 'lines.product']), 'duplicate' => false];
        });
    }
}
