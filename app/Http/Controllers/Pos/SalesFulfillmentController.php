<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\DeliveryRequest;
use App\Http\Requests\Pos\SalesOrderRequest;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Unit;
use App\Models\Payment;
use App\Services\AuditService;
use App\Services\InventoryLedgerService;
use App\Services\StockReservationService;
use App\Services\UomConversionService;
use App\Services\WarehouseFulfillmentService;
use App\Services\ProductLifecycleService;
use App\Services\NumberingSequenceService;
use App\Models\DeliveryOperation;
use App\Models\CustomerProductPrice;
use App\Services\SerialLifecycleService;
use App\Services\PromotionService;
use App\Services\BundleFulfillmentService;
use App\Models\InventoryLocation;
use App\Models\InventoryBatch;
use App\Models\InventoryMovementAllocation;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesFulfillmentController extends Controller
{
    private function companyId(): int
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        abort_unless($companyId, 422, 'A company is required for sales fulfillment.');
        return $companyId;
    }

    private function companyOrder(int $id): SalesOrder
    {
        return SalesOrder::where('company_id', $this->companyId())->findOrFail($id);
    }

    private function companyDelivery(int $id): Delivery
    {
        return Delivery::where('company_id', $this->companyId())->findOrFail($id);
    }

    public function orders() { $orders = SalesOrder::where('company_id', $this->companyId())->with(['customer', 'promotion'])->latest()->paginate(30); return view('backend.invoice.order_all', compact('orders')); }
    public function createOrder(Request $request) { $companyId = $this->companyId(); $customers = Customer::where('status', 1)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('name')->get(); $products = Product::where('status', 1)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('name')->get(); $units = Unit::where('status', 1)->orderBy('name')->get(); $locations = InventoryLocation::where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('code')->get(); $stores = Store::where('is_active', true)->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))->with('branch')->orderBy('name')->get(); $selectedCustomerId = (int) $request->input('customer_id') ?: null; $selectedProductId = (int) $request->input('product_id') ?: null; $suggestedQuantity = $request->input('quantity'); $suggestedPrice = $request->input('price'); return view('backend.invoice.order_add', compact('customers', 'products', 'units', 'locations', 'stores', 'selectedCustomerId', 'selectedProductId', 'suggestedQuantity', 'suggestedPrice')); }
    public function storeOrder(SalesOrderRequest $request)
    {
        $companyId = $this->companyId();
        $promotionCode = $request->input('promotion_code');
        if ($promotionCode !== null && $promotionCode !== '') $request->validate(['promotion_code' => ['string', 'max:80']]);
        $store = $request->filled('store_id') ? Store::whereHas('branch', fn ($query) => $query->where('company_id', $companyId))->with('warehouse')->findOrFail((int) $request->input('store_id')) : null;
        if ($store?->warehouse_id && $request->filled('location_id') && !InventoryLocation::whereKey((int) $request->input('location_id'))->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->whereHas('warehouse', fn ($query) => $query->whereKey($store->warehouse_id))->exists()) return back()->withErrors(['location_id' => 'The fulfillment location must belong to the store warehouse.'])->withInput();
        if ($request->filled('location_id') && !InventoryLocation::whereKey((int) $request->input('location_id'))->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId))->exists()) abort(422, 'The fulfillment location is outside the current company.');
        DB::transaction(function () use ($request, $promotionCode, $companyId): void {
            $order = SalesOrder::create($request->only(['customer_id', 'store_id', 'date', 'requested_date', 'description', 'allow_backorders']) + ['company_id' => $companyId, 'location_id' => $request->location_id, 'currency_code' => strtoupper($request->currency_code ?: (auth()->user()?->company?->base_currency ?? 'USD')), 'exchange_rate' => $request->exchange_rate ?: 1, 'order_no' => $request->order_no ?: app(NumberingSequenceService::class)->nextOrFallback('sales_order', 'SO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, auth()->user()?->branch_id), 'status' => 'submitted', 'created_by' => auth()->id()]);
            $lineRows = [];
            foreach ($request->product_id as $index => $productId) {
                $product = Product::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->findOrFail($productId);
                app(ProductLifecycleService::class)->assertSellable($product);
                $preferredBatchId = !empty($request->batch_id[$index]) ? (int) $request->batch_id[$index] : null;
                if ($preferredBatchId !== null && (!in_array($product->tracking_type, ['batch', 'lot'], true) || !InventoryBatch::whereKey($preferredBatchId)->where('product_id', $product->id)->exists())) abort(422, 'The selected batch does not belong to the selected batch-tracked product.');
                $uomId = $request->uom_id[$index] ?? null;
                $enteredQty = (float) $request->ordered_qty[$index];
                $stockQty = app(UomConversionService::class)->toStock($product, $enteredQty, $uomId ? (int) $uomId : null, 'sales');
                $unitPrice = $uomId ? (float) $request->unit_price[$index] / max($stockQty / $enteredQty, 0.000001) : (float) $request->unit_price[$index];
                $priceAgreement = app(\App\Services\CustomerProductPriceService::class)->bestFor($product, $order->customer, $stockQty, now()->toDateString(), $order->currency_code);
                $discount = (float) ($request->discount_amount[$index] ?? 0);
                if ($priceAgreement && (float) $request->unit_price[$index] <= 0) {
                    $unitPrice = (float) $priceAgreement->unit_price;
                    $discount = $stockQty * $unitPrice * ((float) $priceAgreement->discount_percent / 100);
                }
                $lineRows[] = ['product_id' => (int) $productId, 'batch_id' => $preferredBatchId, 'uom_id' => $uomId, 'uom_quantity' => $enteredQty, 'quantity' => $stockQty, 'unit_price' => $unitPrice, 'discount' => $discount];
            }
            $promotionResult = app(PromotionService::class)->applyToLines($promotionCode, $lineRows, (int) $order->customer_id, $order->date->toDateString());
            if ($promotionResult['promotion']) $order->update(['promotion_id' => $promotionResult['promotion']->id]);
            foreach ($promotionResult['lines'] as $line) SalesOrderLine::create(['sales_order_id' => $order->id, 'product_id' => $line['product_id'], 'batch_id' => $line['batch_id'] ?? null, 'uom_id' => $line['uom_id'], 'uom_quantity' => $line['uom_quantity'], 'ordered_qty' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount_amount' => $line['discount']]);
            app(AuditService::class)->record('sales_order.created', $order, null, $order->toArray());
        });
        return redirect()->route('fulfillment.orders')->with(['message' => 'Sales order submitted.', 'alert-type' => 'success']);
    }
    public function approveOrder(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(SalesOrder::class, $id);
        $order = SalesOrder::where('company_id', $this->companyId())->with(['lines', 'customer'])->findOrFail($id);
        if ($order->status !== 'submitted') return back()->with(['message' => 'Only submitted orders can be approved.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($order); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        try { app(\App\Services\SalesDiscountPolicyService::class)->assertCanApprove($order); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        try { foreach ($order->lines as $line) app(ProductLifecycleService::class)->assertSellable($line->product); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $orderValue = (float) $order->lines->sum(fn ($line) => ((float) $line->ordered_qty * (float) $line->unit_price) - (float) $line->discount_amount);
        try { app(\App\Services\CustomerCreditService::class)->assertCanApprove($order->customer, $orderValue); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        try { app(StockReservationService::class)->reserveSalesOrder($order->load('lines')); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $order->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        if ($order->promotion_id) app(PromotionService::class)->redeem((int) $order->promotion_id);
        app(AuditService::class)->record('sales_order.approved', $order, ['status' => 'submitted'], ['status' => 'approved']);
        return back()->with(['message' => 'Sales order approved.', 'alert-type' => 'success']);
    }
    public function cancelOrder(Request $request, int $id)
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        try {
            DB::transaction(function () use ($data, $id): void {
                $order = SalesOrder::where('company_id', $this->companyId())->with('lines')->lockForUpdate()->findOrFail($id);
                if ($order->status === 'cancelled') throw new \RuntimeException('This sales order has already been cancelled.');
                if ($order->status === 'delivered') throw new \RuntimeException('Fully delivered orders cannot be cancelled; use a return workflow.');
                if (!in_array($order->status, ['submitted', 'approved', 'partially_delivered'], true)) throw new \RuntimeException('Only submitted or open sales orders can be cancelled.');
                $before = $order->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']);
                $released = app(StockReservationService::class)->releaseSalesOrder($order);
                $order->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
                app(AuditService::class)->record('sales_order.cancelled', $order, $before, $order->fresh()->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at', 'released_reservations' => $released]));
            });
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        return back()->with(['message' => 'Sales order cancelled and open reservations released.', 'alert-type' => 'success']);
    }
    public function rejectOrder(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $order = $this->companyOrder($id);
        if ($order->status !== 'submitted') return back()->with(['message' => 'Only submitted sales orders can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($order); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $order->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $order->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('sales_order.rejected', $order, $before, $order->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Sales order rejected.', 'alert-type' => 'success']);
    }
    public function deliveries() { $deliveries = Delivery::where('company_id', $this->companyId())->with(['salesOrder.customer', 'operations', 'lines.product'])->latest()->paginate(30); return view('backend.invoice.delivery_all', compact('deliveries')); }
    public function createDelivery() { $companyId = $this->companyId(); $orders = SalesOrder::where('company_id', $companyId)->whereIn('status', ['approved', 'partially_delivered'])->with(['customer', 'lines.product'])->latest()->get(); $locations = InventoryLocation::where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderBy('code')->get(); $units = \App\Models\Unit::where('status', 1)->orderBy('name')->get(['id', 'name']); return view('backend.invoice.delivery_add', compact('orders', 'locations', 'units')); }
    public function storeDelivery(DeliveryRequest $request)
    {
        $request->validate(['location_id' => ['nullable', 'integer', 'exists:inventory_locations,id']]);
        DB::transaction(function () use ($request): void {
            $order = SalesOrder::where('company_id', $this->companyId())->whereIn('status', ['approved', 'partially_delivered'])->findOrFail($request->sales_order_id);
            if ($request->filled('location_id') && !InventoryLocation::whereKey((int) $request->input('location_id'))->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->exists()) abort(422, 'The delivery location is outside the current company.');
            if ($order->location_id && $request->filled('location_id') && (int) $order->location_id !== (int) $request->input('location_id')) throw new \RuntimeException('Delivery location must match the sales-order fulfillment location.');
            $delivery = Delivery::create($request->only(['date', 'delivery_address', 'carrier', 'tracking_no', 'package_count', 'total_weight', 'weight_unit', 'length', 'width', 'height', 'proof_of_delivery', 'description']) + ['company_id' => $order->company_id ?: auth()->user()?->company_id, 'delivery_no' => $request->delivery_no ?: app(NumberingSequenceService::class)->nextOrFallback('delivery', 'DN-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'sales_order_id' => $order->id, 'location_id' => $request->location_id ?: $order->location_id, 'created_by' => auth()->id()]);
            foreach ($request->line_id as $index => $lineId) {
                $line = $order->lines()->whereKey($lineId)->firstOrFail();
                $uomId = $request->uom_id[$index] ?? null;
                $enteredQty = (float) $request->delivered_qty[$index];
                $deliveredQty = app(\App\Services\UomConversionService::class)->toStock($line->product, $enteredQty, $uomId ? (int) $uomId : null, 'sales');
                $conversion = $enteredQty > 0 ? $deliveredQty / $enteredQty : 1;
                $remaining = (float) $line->ordered_qty - (float) $line->delivered_qty;
                if ($deliveredQty > $remaining) throw new \RuntimeException('Delivery exceeds remaining quantity for '.$line->product->name.'.');
                DeliveryLine::create(['delivery_id' => $delivery->id, 'sales_order_line_id' => $line->id, 'product_id' => $line->product_id, 'uom_id' => $uomId, 'uom_quantity' => $enteredQty, 'delivered_qty' => $deliveredQty, 'unit_price' => (float) $request->unit_price[$index] / max($conversion, 0.000001), 'batch_no' => $request->batch_no[$index] ?? null, 'serial_numbers' => $request->serial_numbers[$index] ?? null]);
            }
            DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'pick']);
            app(AuditService::class)->record('delivery.created', $delivery, null, $delivery->toArray());
        });
        return redirect()->route('fulfillment.deliveries')->with(['message' => 'Delivery submitted for approval.', 'alert-type' => 'success']);
    }
    public function completeOperation(int $id, string $type)
    {
        $confirmedQuantities = request()->input($type === 'pack' ? 'packed_quantity' : 'picked_quantity');
        if (in_array($type, ['pick', 'pack'], true) && $confirmedQuantities !== null) request()->validate([$type === 'pack' ? 'packed_quantity' : 'picked_quantity' => ['array'], ($type === 'pack' ? 'packed_quantity' : 'picked_quantity').'.*' => ['numeric', 'min:0']]);
        try { $delivery = $this->companyDelivery($id); if ($delivery->fulfillment_status === 'cancelled') throw new \RuntimeException('Cancelled deliveries cannot be operated.'); app(WarehouseFulfillmentService::class)->complete($delivery, $type, is_array($confirmedQuantities) ? $confirmedQuantities : null); app(AuditService::class)->record('delivery.'.$type.'.completed', $delivery, null, ['operation_type' => $type, 'confirmed_quantities' => $confirmedQuantities]); return back()->with(['message' => ucfirst($type).' operation completed.', 'alert-type' => 'success']); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function confirmDelivered(int $id)
    {
        $delivery = Delivery::where('company_id', $this->companyId())->with('operations')->findOrFail($id);
        if ($delivery->fulfillment_status === 'cancelled' || $delivery->status !== 'approved' || !$delivery->operations->firstWhere('operation_type', 'dispatch')) return back()->with(['message' => 'Only dispatched deliveries can be marked delivered.', 'alert-type' => 'error']);
        $delivery->update(['fulfillment_status' => 'delivered', 'delivered_at' => now()]);
        app(WarehouseFulfillmentService::class)->markDelivered($delivery, $delivery->delivered_at);
        app(AuditService::class)->record('delivery.delivered', $delivery, ['fulfillment_status' => $delivery->getOriginal('fulfillment_status')], ['fulfillment_status' => 'delivered', 'delivered_at' => now()->toISOString()]);
        return back()->with(['message' => 'Delivery marked as delivered.', 'alert-type' => 'success']);
    }
    public function cancelDelivery(Request $request, int $id)
    {
        $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        try {
            DB::transaction(function () use ($request, $id): void {
                $delivery = Delivery::where('company_id', $this->companyId())->with(['lines.salesOrderLine', 'lines.product', 'salesOrder.lines'])->lockForUpdate()->findOrFail($id);
                $before = $delivery->only(['status', 'fulfillment_status', 'cancellation_reason']);
                $reason = $request->string('cancellation_reason')->toString();
                if ($delivery->fulfillment_status === 'cancelled') throw new \RuntimeException('This delivery has already been cancelled.');
                if ($delivery->status === 'pending' && ($delivery->fulfillment_status ?: 'pending') === 'pending') {
                    $delivery->update(['fulfillment_status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
                    app(WarehouseFulfillmentService::class)->markCancelled($delivery);
                } elseif ($delivery->status === 'approved' && $delivery->fulfillment_status === 'dispatched') {
                    foreach ($delivery->lines as $line) {
                        $product = Product::lockForUpdate()->findOrFail($line->product_id);
                        if (($product->product_type ?: 'stock') === 'bundle') throw new \RuntimeException('Dispatched bundle deliveries must be reversed through the sales-return workflow.');
                        $quantity = (float) $line->delivered_qty;
                        $movementAllocations = InventoryMovementAllocation::with('serial')->where('product_id', $product->id)->whereHas('movement', fn ($query) => $query->where('reference_type', $delivery->getMorphClass())->where('reference_id', $delivery->id)->where('movement_type', 'issue'))->lockForUpdate()->get();
                        if ($product->tracking_type === 'serial') {
                            $serialNumbers = $line->issued_serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $line->issued_serial_numbers)))) : [];
                            if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Issued serial history is incomplete for '.$product->name.'. Use a sales return instead.');
                            $serialBatchGroups = collect($serialNumbers)->groupBy(function (string $serialNumber) use ($movementAllocations, $line): int {
                                return (int) ($movementAllocations->first(fn ($allocation): bool => $allocation->serial?->serial_no === $serialNumber)?->batch_id ?: $line->batch_id ?: 0);
                            });
                            foreach ($serialBatchGroups as $batchId => $group) app(SerialLifecycleService::class)->returnToStock($product, $group->all(), $delivery->location_id, $batchId ?: null);
                        }
                        $product->quantity = (float) $product->quantity + $quantity;
                        $product->save();
                        $allocations = $movementAllocations;
                        if ($allocations->isEmpty()) $allocations = collect([['batch_id' => $line->batch_id, 'serial_id' => null, 'quantity' => $quantity]]);
                        foreach ($allocations as $allocation) {
                            $batchId = is_array($allocation) ? $allocation['batch_id'] : $allocation->batch_id;
                            $serialId = is_array($allocation) ? $allocation['serial_id'] : $allocation->serial_id;
                            $reverseQuantity = (float) (is_array($allocation) ? $allocation['quantity'] : $allocation->quantity);
                            app(InventoryLedgerService::class)->post($product->id, 'return_in', $reverseQuantity, (float) $line->unit_price, $delivery->location_id, $delivery, 'Delivery cancellation reversal', null, $batchId, $serialId);
                        }
                        $orderLine = SalesOrderLine::lockForUpdate()->findOrFail($line->sales_order_line_id);
                        $orderLine->delivered_qty = max(0, (float) $orderLine->delivered_qty - $quantity);
                        $orderLine->save();
                    }
                    app(StockReservationService::class)->reserveSalesOrder($delivery->salesOrder->fresh(['lines']));
                    $delivery->update(['fulfillment_status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
                    app(WarehouseFulfillmentService::class)->markCancelled($delivery);
                    $order = $delivery->salesOrder->fresh(['lines']);
                    $order->update(['status' => $order->lines->contains(fn ($line) => (float) $line->delivered_qty > 0) ? 'partially_delivered' : 'approved']);
                } else {
                    throw new \RuntimeException('Only pending or dispatched deliveries can be cancelled. Delivered deliveries require a sales return.');
                }
                app(AuditService::class)->record('delivery.cancelled', $delivery, $before, $delivery->fresh()->only(['status', 'fulfillment_status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']));
            });
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        return back()->with(['message' => 'Delivery cancelled and stock reversal posted.', 'alert-type' => 'success']);
    }
    public function approveDelivery(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(Delivery::class, $id);
        try {
            DB::transaction(function () use ($id): void {
                $delivery = Delivery::where('company_id', $this->companyId())->with(['lines.salesOrderLine', 'lines.product', 'salesOrder'])->lockForUpdate()->findOrFail($id);
                if ($delivery->status !== 'pending' || ($delivery->fulfillment_status ?: 'pending') !== 'pending') throw new \RuntimeException('This delivery has already been processed.');
                if ($delivery->salesOrder->location_id && (int) $delivery->salesOrder->location_id !== (int) $delivery->location_id) throw new \RuntimeException('Delivery location does not match the sales-order fulfillment location.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($delivery);
                app(WarehouseFulfillmentService::class)->assertReadyForDispatch($delivery);
                foreach ($delivery->lines as $line) {
                    $product = Product::lockForUpdate()->findOrFail($line->product_id);
                    $orderLine = SalesOrderLine::lockForUpdate()->findOrFail($line->sales_order_line_id);
                    if (($product->product_type ?: 'stock') === 'bundle') {
                        app(BundleFulfillmentService::class)->issue($product, (float) $line->delivered_qty, $delivery, $orderLine, (float) $line->unit_price);
                        $orderLine->delivered_qty = (float) $orderLine->delivered_qty + (float) $line->delivered_qty;
                        $orderLine->save();
                        continue;
                    }
                    $available = app(\App\Services\InventoryAvailabilityService::class)->available($product, false, $delivery->location_id, $delivery->company_id);
                    if ($available < (float) $line->delivered_qty) throw new \RuntimeException('Insufficient available stock for '.$product->name.'.');
                    $batch = null;
                    if ($line->batch_no) {
                        $batch = InventoryBatch::where('product_id', $product->id)->where('batch_no', $line->batch_no)->lockForUpdate()->first();
                        if (!$batch) throw new \RuntimeException('Batch '.$line->batch_no.' was not found for '.$product->name.'.');
                        if ($batch->location_id && $delivery->location_id && (int) $batch->location_id !== (int) $delivery->location_id) throw new \RuntimeException('Selected batch is not held at the delivery location.');
                    }
                    $batchAllocations = app(StockReservationService::class)->batchAllocations($orderLine->id, (float) $line->delivered_qty, $batch?->id, $delivery->location_id);
                    $issuedSerials = collect();
                    if ($product->tracking_type === 'serial') {
                        $serialNumbers = $line->serial_numbers ? array_filter(array_map('trim', preg_split('/[,\r\n]+/', $line->serial_numbers))) : [];
                        if ($serialNumbers) {
                            if (count($serialNumbers) !== (int) round((float) $line->delivered_qty)) throw new \RuntimeException('Serial count must equal delivered quantity for '.$product->name.'.');
                            $issuedSerials = app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $delivery->location_id, $batch?->id);
                        } else foreach ($batchAllocations as $allocation) $issuedSerials = $issuedSerials->merge(app(SerialLifecycleService::class)->issue($product, (float) $allocation['quantity'], $delivery->location_id, $allocation['batch_id']));
                    }
                    $product->quantity = (float) $product->quantity - (float) $line->delivered_qty;
                    $product->save();
                    $orderLine->delivered_qty = (float) $orderLine->delivered_qty + (float) $line->delivered_qty;
                    $orderLine->save();
                    app(StockReservationService::class)->releaseForSalesOrderLine($orderLine->id, (float) $line->delivered_qty);
                    if ($issuedSerials->isNotEmpty()) {
                        foreach ($issuedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'issue', 1, (float) $line->unit_price, $delivery->location_id, $delivery, 'Approved sales delivery', null, $serial->batch_id ?: $batch?->id, $serial->id);
                    } else {
                        foreach ($batchAllocations as $allocation) app(InventoryLedgerService::class)->post($product->id, 'issue', (float) $allocation['quantity'], (float) $line->unit_price, $delivery->location_id, $delivery, 'Approved sales delivery', null, $allocation['batch_id']);
                    }
                    $line->batch_id = $batch?->id;
                    $line->issued_serial_numbers = $issuedSerials->pluck('serial_no')->implode(',');
                    $line->save();
                }
                $delivery->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(WarehouseFulfillmentService::class)->markDispatched($delivery);
                $order = SalesOrder::where('company_id', $this->companyId())->with('lines')->findOrFail($delivery->sales_order_id);
                $order->update(['status' => $order->lines->every(fn ($line) => (float) $line->delivered_qty >= (float) $line->ordered_qty) ? 'delivered' : 'partially_delivered']);
                app(AuditService::class)->record('delivery.approved', $delivery, ['status' => 'pending'], ['status' => 'approved']);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return redirect()->route('fulfillment.deliveries')->with(['message' => 'Delivery approved and stock issued.', 'alert-type' => 'success']);
    }

    public function rejectDelivery(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $delivery = $this->companyDelivery($id);
        if ($delivery->status !== 'pending' || ($delivery->fulfillment_status ?: 'pending') !== 'pending') return back()->with(['message' => 'Only pending deliveries can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($delivery); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $delivery->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $delivery->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('delivery.rejected', $delivery, $before, $delivery->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Delivery rejected.', 'alert-type' => 'success']);
    }
}
