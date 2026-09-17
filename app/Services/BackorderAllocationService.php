<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StockReservation;
use Illuminate\Support\Facades\DB;

class BackorderAllocationService
{
    public function allocateForCompany(int $companyId): int
    {
        $allocated = 0;
        SalesOrder::where('company_id', $companyId)
            ->where('status', 'approved')
            ->where('allow_backorders', true)
            ->orderByRaw('requested_date IS NULL')
            ->orderBy('requested_date')
            ->orderBy('id')
            ->with('lines')
            ->get()
            ->each(function (SalesOrder $order) use ($companyId, &$allocated): void {
                $added = DB::transaction(function () use ($order, $companyId): int {
                    $order = SalesOrder::with('lines')->lockForUpdate()->findOrFail($order->id);
                    if ($order->status !== 'approved' || !$order->allow_backorders) return 0;
                    $added = 0;
                    foreach ($order->lines as $line) {
                        $required = (float) $line->ordered_qty - (float) $line->delivered_qty;
                        if ($required <= 0.000001) continue;
                        $reserved = (float) StockReservation::where('sales_order_line_id', $line->id)->where('status', 'active')->sum(DB::raw('quantity - released_quantity'));
                        $needed = $required - $reserved;
                        if ($needed <= 0.000001) continue;
                        $product = Product::lockForUpdate()->findOrFail($line->product_id);
                        $available = app(InventoryAvailabilityService::class)->available($product, true, $order->location_id, $companyId);
                        $quantity = min($needed, max(0, $available));
                        if ($quantity <= 0.000001) continue;
                        StockReservation::create(['company_id' => $companyId, 'product_id' => $product->id, 'location_id' => $order->location_id, 'sales_order_line_id' => $line->id, 'quantity' => $quantity, 'created_by' => null]);
                        $added += $quantity;
                    }
                    return $added;
                });
                if ($added > 0.000001) {
                    $allocated += $added;
                    app(AuditService::class)->record('sales_order.backorder_allocated', $order, null, ['quantity' => $added]);
                }
            });
        return $allocated;
    }
}
