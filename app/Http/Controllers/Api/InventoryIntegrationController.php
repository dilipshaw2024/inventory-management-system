<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\InventoryStatusBalance;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Customer;
use App\Models\Store;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DeliveryOperation;
use App\Models\PurchaseOrder;
use App\Models\GoodsReceipt;
use App\Models\PurchaseInvoice;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\CustomerContact;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentLine;
use App\Models\InventoryReturn;
use App\Models\InventoryLocation;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\InventoryTransfer;
use App\Models\StockCount;
use App\Models\InventoryBatch;
use App\Models\InventoryReconciliationSnapshot;
use App\Models\InventoryCostLayerAdjustment;
use App\Models\JournalEntry;
use App\Services\AuditService;
use App\Services\InventoryAvailabilityService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use App\Services\UomConversionService;
use App\Services\CustomerProductPriceService;
use App\Services\PromotionService;
use App\Services\SerialLifecycleService;
use App\Services\WarehouseFulfillmentService;
use App\Services\ProductLifecycleService;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\InventoryDocumentApprovalService;
use App\Services\ApprovalGuard;
use App\Services\TaxCalculationService;
use App\Services\TaxRateResolver;
use App\Services\CurrencyConversionService;
use App\Services\AutomaticAccountingService;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryIntegrationController extends Controller
{
    public function createDelivery(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('deliveries', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'sales_order_id' => ['required', 'integer', $owned('sales_orders')], 'date' => ['required', 'date'], 'location_id' => ['nullable', 'integer'],
            'delivery_address' => ['nullable', 'string', 'max:2000'], 'carrier' => ['nullable', 'string', 'max:255'], 'tracking_no' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.sales_order_line_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom_id' => ['nullable', 'integer', $owned('units')], 'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:100'], 'lines.*.serial_numbers' => ['nullable', 'string', 'max:5000'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(Delivery::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('salesOrder', 'lines.product'), 'status' => 'duplicate_ignored']);
        }
        $order = $this->companyScope(SalesOrder::with('lines.product'), $companyId)->findOrFail($data['sales_order_id']);
        if (!in_array($order->status, ['approved', 'partially_delivered'], true)) abort(422, 'Deliveries require an approved or partially delivered sales order.');
        if (!empty($data['location_id']) && !$this->companyScope(InventoryLocation::query(), $companyId)->whereKey($data['location_id'])->exists()) abort(422, 'Location is not authorized for this company.');
        if ($order->location_id && !empty($data['location_id']) && (int) $order->location_id !== (int) $data['location_id']) abort(422, 'Delivery location must match the sales-order fulfillment location.');
        $delivery = DB::transaction(function () use ($data, $order, $companyId, $request): Delivery {
            $delivery = Delivery::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null, 'sales_order_id' => $order->id,
                'date' => $data['date'], 'location_id' => $data['location_id'] ?? $order->location_id, 'delivery_address' => $data['delivery_address'] ?? null,
                'carrier' => $data['carrier'] ?? null, 'tracking_no' => $data['tracking_no'] ?? null, 'description' => $data['description'] ?? null,
                'delivery_no' => app(NumberingSequenceService::class)->nextOrFallback('delivery', 'DN-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'status' => 'pending', 'created_by' => $request->user()?->id,
            ]);
            foreach ($data['lines'] as $line) {
                $orderLine = $order->lines->firstWhere('id', (int) $line['sales_order_line_id']);
                if (!$orderLine) throw new \RuntimeException('A delivery line does not belong to the selected sales order.');
                $enteredQuantity = (float) $line['quantity']; $uomId = $line['uom_id'] ?? null;
                $deliveredQuantity = app(UomConversionService::class)->toStock($orderLine->product, $enteredQuantity, $uomId ? (int) $uomId : null, 'sales');
                $remaining = (float) $orderLine->ordered_qty - (float) $orderLine->delivered_qty;
                if ($deliveredQuantity > $remaining + 0.000001) throw new \RuntimeException('Delivery exceeds remaining quantity for '.$orderLine->product->name.'.');
                $conversion = $enteredQuantity > 0 ? $deliveredQuantity / $enteredQuantity : 1;
                DeliveryLine::create(['delivery_id' => $delivery->id, 'sales_order_line_id' => $orderLine->id, 'product_id' => $orderLine->product_id, 'uom_id' => $uomId, 'uom_quantity' => $enteredQuantity, 'delivered_qty' => $deliveredQuantity, 'unit_price' => (float) ($line['unit_price'] ?? $orderLine->unit_price) / max($conversion, 0.000001), 'batch_no' => $line['batch_no'] ?? null, 'serial_numbers' => $line['serial_numbers'] ?? null]);
            }
            DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'pick']);
            app(AuditService::class)->record('delivery.created', $delivery, null, $delivery->toArray());
            return $delivery;
        });
        return response()->json(['data' => $delivery->load('salesOrder', 'lines.product'), 'status' => 'pending_approval'], 201);
    }

    public function approveDelivery(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(Delivery::class, $id);
        $delivery = DB::transaction(function () use ($id): Delivery {
            $delivery = $this->companyScope(Delivery::with(['lines.salesOrderLine', 'lines.product', 'salesOrder']), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($delivery->status !== 'pending' || ($delivery->fulfillment_status ?: 'pending') !== 'pending') throw new \RuntimeException('This delivery has already been processed.');
            if ($delivery->salesOrder->location_id && (int) $delivery->salesOrder->location_id !== (int) $delivery->location_id) throw new \RuntimeException('Delivery location does not match the sales-order fulfillment location.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($delivery);
            app(WarehouseFulfillmentService::class)->assertReadyForDispatch($delivery);
            foreach ($delivery->lines as $line) {
                $product = $this->companyScope(Product::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($line->product_id);
                $orderLine = SalesOrderLine::whereHas('salesOrder', fn ($query) => $this->companyScope($query, auth()->user()?->company_id))->lockForUpdate()->findOrFail($line->sales_order_line_id);
                if (($product->product_type ?: 'stock') === 'bundle') {
                    app(\App\Services\BundleFulfillmentService::class)->issue($product, (float) $line->delivered_qty, $delivery, $orderLine, (float) $line->unit_price);
                    $orderLine->delivered_qty = (float) $orderLine->delivered_qty + (float) $line->delivered_qty;
                    $orderLine->save();
                    continue;
                }
                if (app(InventoryAvailabilityService::class)->available($product, false, $delivery->location_id, $delivery->company_id) < (float) $line->delivered_qty) throw new \RuntimeException('Insufficient available stock for '.$product->name.'.');
                $batch = null;
                if ($line->batch_no) {
                    $batch = InventoryBatch::where('product_id', $product->id)->where('batch_no', $line->batch_no)->lockForUpdate()->first();
                    if (!$batch) throw new \RuntimeException('Batch '.$line->batch_no.' was not found for '.$product->name.'.');
                    if ($batch->location_id && $delivery->location_id && (int) $batch->location_id !== (int) $delivery->location_id) throw new \RuntimeException('Selected batch is not held at the delivery location.');
                }
                $batchAllocations = app(\App\Services\StockReservationService::class)->batchAllocations($orderLine->id, (float) $line->delivered_qty, $batch?->id, $delivery->location_id);
                $issuedSerials = collect();
                if ($product->tracking_type === 'serial') {
                    $serialNumbers = $line->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\\r\\n]+/', $line->serial_numbers)))) : [];
                    if ($serialNumbers) {
                        if (count($serialNumbers) !== (int) round((float) $line->delivered_qty)) throw new \RuntimeException('Serial count must equal delivered quantity for '.$product->name.'.');
                        $issuedSerials = app(SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $delivery->location_id, $batch?->id);
                    } else foreach ($batchAllocations as $allocation) $issuedSerials = $issuedSerials->merge(app(SerialLifecycleService::class)->issue($product, (float) $allocation['quantity'], $delivery->location_id, $allocation['batch_id']));
                }
                $product->quantity = (float) $product->quantity - (float) $line->delivered_qty;
                $product->save();
                $orderLine->delivered_qty = (float) $orderLine->delivered_qty + (float) $line->delivered_qty;
                $orderLine->save();
                app(\App\Services\StockReservationService::class)->releaseForSalesOrderLine($orderLine->id, (float) $line->delivered_qty);
                if ($issuedSerials->isNotEmpty()) foreach ($issuedSerials as $serial) app(\App\Services\InventoryLedgerService::class)->post($product->id, 'issue', 1, (float) $line->unit_price, $delivery->location_id, $delivery, 'Approved sales delivery', null, $serial->batch_id ?: $batch?->id, $serial->id);
                else foreach ($batchAllocations as $allocation) app(\App\Services\InventoryLedgerService::class)->post($product->id, 'issue', (float) $allocation['quantity'], (float) $line->unit_price, $delivery->location_id, $delivery, 'Approved sales delivery', null, $allocation['batch_id']);
                $line->batch_id = $batch?->id; $line->issued_serial_numbers = $issuedSerials->pluck('serial_no')->implode(','); $line->save();
            }
            $delivery->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            app(WarehouseFulfillmentService::class)->markDispatched($delivery);
            $order = $this->companyScope(SalesOrder::with('lines'), auth()->user()?->company_id)->findOrFail($delivery->sales_order_id);
            $order->update(['status' => $order->lines->every(fn ($line) => (float) $line->delivered_qty >= (float) $line->ordered_qty) ? 'delivered' : 'partially_delivered']);
            app(AuditService::class)->record('delivery.approved', $delivery, ['status' => 'pending'], ['status' => 'approved']);
            return $delivery->fresh();
        });
        return response()->json(['data' => $delivery->load('salesOrder', 'lines.product', 'operations'), 'status' => $delivery->status]);
    }

    public function confirmDelivery(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['proof_of_delivery' => ['nullable', 'string', 'max:255'], 'delivered_at' => ['nullable', 'date']]);
        $delivery = DB::transaction(function () use ($data, $id): Delivery {
            $delivery = $this->companyScope(Delivery::with('operations'), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($delivery->fulfillment_status === 'cancelled' || $delivery->status !== 'approved' || !$delivery->operations->firstWhere('operation_type', 'dispatch')) {
                throw new \RuntimeException('Only dispatched deliveries can be marked delivered.');
            }
            $before = $delivery->only(['fulfillment_status', 'delivered_at', 'proof_of_delivery']);
            $delivery->update(['fulfillment_status' => 'delivered', 'delivered_at' => $data['delivered_at'] ?? now(), 'proof_of_delivery' => $data['proof_of_delivery'] ?? $delivery->proof_of_delivery]);
            app(AuditService::class)->record('delivery.delivered', $delivery, $before, $delivery->only(['fulfillment_status', 'delivered_at', 'proof_of_delivery']));
            return $delivery->fresh();
        });
        return response()->json(['data' => $delivery->load('salesOrder', 'lines.product', 'operations'), 'status' => $delivery->fulfillment_status]);
    }

    public function completeDeliveryOperation(Request $request, int $id, string $type): JsonResponse
    {
        if (!in_array($type, ['pick', 'pack'], true)) abort(422, 'Only pick and pack operations can be completed through this endpoint.');
        if (!$request->user()?->tokenCan('warehouse:write')
            && !$request->user()?->tokenCan('sales:write')
            && !$request->user()?->tokenCan('integration:write')) {
            abort(403, 'This token cannot complete warehouse fulfillment operations.');
        }

        $data = $request->validate([
            'confirmed_quantities' => ['nullable', 'array'],
            'confirmed_quantities.*' => ['numeric', 'min:0'],
        ]);

        try {
            $operation = DB::transaction(function () use ($request, $id, $type, $data): DeliveryOperation {
                $delivery = $this->companyScope(Delivery::with(['lines.product', 'operations']), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                $operation = app(WarehouseFulfillmentService::class)->complete(
                    $delivery,
                    $type,
                    isset($data['confirmed_quantities']) ? $data['confirmed_quantities'] : null
                );
                app(AuditService::class)->record('delivery.'.$type.'.completed', $delivery, null, [
                    'operation_id' => $operation->id,
                    'confirmed_quantities' => $operation->confirmed_quantities,
                    'performed_by' => $request->user()?->id,
                ]);
                return $operation->fresh();
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $operation->load('delivery'), 'status' => $operation->status]);
    }

    public function createSalesOrder(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        if ($request->input('promotion_code') !== null && $request->input('promotion_code') !== '') $request->validate(['promotion_code' => ['string', 'max:80']]);
        $promotionCode = $request->input('promotion_code');
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('sales_orders', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'customer_id' => ['required', 'integer', $owned('customers')], 'store_id' => ['nullable', 'integer', $owned('stores')],
            'location_id' => ['nullable', 'integer'], 'date' => ['required', 'date'], 'requested_date' => ['nullable', 'date', 'after_or_equal:date'],
            'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'allow_backorders' => ['nullable', 'boolean'], 'description' => ['nullable', 'string', 'max:2000'], 'promotion_code' => ['nullable', 'string', 'max:80'], 'promotion_codes' => ['nullable', 'array', 'max:10'], 'promotion_codes.*' => ['string', 'max:80', 'distinct'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer', $owned('products')],
            'lines.*.uom_id' => ['nullable', 'integer', $owned('units')], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'], 'lines.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(SalesOrder::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('customer', 'lines.product'), 'status' => 'duplicate_ignored']);
        }
        $customer = $this->companyScope(Customer::query(), $companyId)->findOrFail($data['customer_id']);
        $store = !empty($data['store_id']) ? $this->companyScope(Store::query(), $companyId)->findOrFail($data['store_id']) : null;
        if (!empty($data['location_id']) && !$this->companyScope(InventoryLocation::query(), $companyId)->whereKey($data['location_id'])->exists()) abort(422, 'Location is not authorized for this company.');
        $promotionCodes = $data['promotion_codes'] ?? ($promotionCode ? [$promotionCode] : []);
        $order = DB::transaction(function () use ($data, $companyId, $customer, $store, $request, $promotionCodes): SalesOrder {
            $order = SalesOrder::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null, 'customer_id' => $customer->id,
                'store_id' => $store?->id, 'location_id' => $data['location_id'] ?? null, 'date' => $data['date'],
                'requested_date' => $data['requested_date'] ?? null, 'description' => $data['description'] ?? null,
                'allow_backorders' => $data['allow_backorders'] ?? false, 'currency_code' => strtoupper($data['currency_code'] ?? ($request->user()?->company?->base_currency ?? 'USD')),
                'exchange_rate' => $data['exchange_rate'] ?? 1, 'order_no' => app(NumberingSequenceService::class)->nextOrFallback('sales_order', 'SO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'status' => 'submitted', 'created_by' => $request->user()?->id,
            ]);
            $lineRows = [];
            foreach ($data['lines'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                app(ProductLifecycleService::class)->assertSellable($product);
                $enteredQuantity = (float) $line['quantity']; $uomId = $line['uom_id'] ?? null;
                $stockQuantity = app(UomConversionService::class)->toStock($product, $enteredQuantity, $uomId ? (int) $uomId : null, 'sales');
                $unitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null ? (float) $line['unit_price'] : 0;
                $discount = (float) ($line['discount_amount'] ?? 0);
                $agreement = app(CustomerProductPriceService::class)->bestFor($product, $customer, $stockQuantity, now()->toDateString(), $order->currency_code);
                if ($agreement && $unitPrice <= 0) { $unitPrice = (float) $agreement->unit_price; $discount = $stockQuantity * $unitPrice * ((float) $agreement->discount_percent / 100); }
                if ($uomId) $unitPrice /= max($stockQuantity / $enteredQuantity, 0.000001);
                $lineRows[] = ['product_id' => (int) $product->id, 'uom_id' => $uomId, 'uom_quantity' => $enteredQuantity, 'quantity' => $stockQuantity, 'unit_price' => $unitPrice, 'discount' => $discount];
            }
            $promotionResult = app(PromotionService::class)->applyCodesToLines($promotionCodes, $lineRows, (int) $order->customer_id, $order->date->toDateString());
            if ($promotionResult['promotion']) $order->update(['promotion_id' => $promotionResult['promotion']->id, 'promotion_ids' => collect($promotionResult['promotions'])->pluck('id')->values()->all()]);
            foreach ($promotionResult['lines'] as $line) SalesOrderLine::create(['sales_order_id' => $order->id, 'product_id' => $line['product_id'], 'uom_id' => $line['uom_id'], 'uom_quantity' => $line['uom_quantity'], 'ordered_qty' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount_amount' => $line['discount']]);
            app(AuditService::class)->record('sales_order.created', $order, null, $order->toArray());
            return $order;
        });
        return response()->json(['data' => $order->load('customer', 'lines.product'), 'status' => 'pending_approval'], 201);
    }

    public function approveSalesOrder(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(SalesOrder::class, $id);
        $pendingOrder = $this->companyScope(SalesOrder::with('lines'), auth()->user()?->company_id)->findOrFail($id);
        try { app(\App\Services\SalesDiscountPolicyService::class)->assertCanApprove($pendingOrder); }
        catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        $order = DB::transaction(function () use ($id): SalesOrder {
            $order = $this->companyScope(SalesOrder::with(['lines', 'customer']), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'submitted') throw new \RuntimeException('Only submitted sales orders can be approved.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($order);
            foreach ($order->lines as $line) app(ProductLifecycleService::class)->assertSellable($line->product);
            $orderValue = (float) $order->lines->sum(fn ($line) => ((float) $line->ordered_qty * (float) $line->unit_price) - (float) $line->discount_amount);
            app(\App\Services\CustomerCreditService::class)->assertCanApprove($order->customer, $orderValue);
            app(\App\Services\StockReservationService::class)->reserveSalesOrder($order);
            $order->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            foreach (($order->promotion_ids ?: ($order->promotion_id ? [$order->promotion_id] : [])) as $promotionId) app(PromotionService::class)->redeem((int) $promotionId);
            app(AuditService::class)->record('sales_order.approved', $order, ['status' => 'submitted'], ['status' => 'approved']);
            return $order->fresh();
        });
        return response()->json(['data' => $order->load('customer', 'lines.product'), 'status' => $order->status]);
    }

    public function cancelSalesOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        $order = DB::transaction(function () use ($id, $data): SalesOrder {
            $order = $this->companyScope(SalesOrder::with('lines'), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($order->status === 'cancelled') throw new \RuntimeException('This sales order has already been cancelled.');
            if ($order->status === 'delivered') throw new \RuntimeException('Fully delivered orders cannot be cancelled; use a return workflow.');
            if (!in_array($order->status, ['submitted', 'approved', 'partially_delivered'], true)) throw new \RuntimeException('Only submitted or open sales orders can be cancelled.');
            $before = $order->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']);
            $released = app(\App\Services\StockReservationService::class)->releaseSalesOrder($order);
            $order->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
            app(AuditService::class)->record('sales_order.cancelled', $order, $before, $order->fresh()->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at', 'released_reservations' => $released]));
            return $order->fresh();
        });
        return response()->json(['data' => $order->load('customer', 'lines.product'), 'status' => $order->status]);
    }

    public function products(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'], 'lifecycle_status' => ['nullable', 'in:draft,active,discontinued,blocked,archived'],
            'product_type' => ['nullable', 'in:stock,service,consumable,asset,bundle'], 'can_purchase' => ['nullable', 'boolean'],
            'can_sell' => ['nullable', 'boolean'], 'is_stock_item' => ['nullable', 'boolean'], 'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $boolean = static fn ($value): bool => (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $products = $this->companyScope(Product::with(['category', 'unit', 'brand', 'barcodes']), $companyId)
            ->when($data['q'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', $search);
                });
            })
            ->when($data['lifecycle_status'] ?? null, fn ($query, $status) => $query->where('lifecycle_status', $status))
            ->when($data['product_type'] ?? null, fn ($query, $type) => $query->where('product_type', $type))
            ->when(array_key_exists('can_purchase', $data), fn ($query) => $query->where('can_purchase', $boolean($data['can_purchase'])))
            ->when(array_key_exists('can_sell', $data), fn ($query) => $query->where('can_sell', $boolean($data['can_sell'])))
            ->when(array_key_exists('is_stock_item', $data), fn ($query) => $query->where('is_stock_item', $boolean($data['is_stock_item'])))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate($products, $request, 'inventory.products', (int) ($data['per_page'] ?? 50));
    }

    public function stock(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $locationId = $request->integer('location_id') ?: null;
        if ($locationId !== null && !$this->companyScope(InventoryLocation::query(), $companyId)->whereKey($locationId)->exists()) abort(422, 'Location is not authorized for this company.');
        $products = $this->companyScope(Product::query(), $companyId)->when($request->input('product_id'), fn ($query, $id) => $query->whereKey($id))
            ->when($request->input('sku'), fn ($query, $sku) => $query->where('sku', $sku))
            ->orderBy('id')->get();
        $ids = $products->pluck('id');
        $reserved = $this->companyScope(StockReservation::query(), $companyId)->whereIn('product_id', $ids)->where('status', 'active')->when($locationId !== null, fn ($query) => $query->where(function ($nested) use ($locationId): void { $nested->whereNull('location_id')->orWhere('location_id', $locationId); }))->selectRaw('product_id, SUM(quantity - released_quantity) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $quality = $this->companyScope(InventoryStatusBalance::query(), $companyId)->whereIn('product_id', $ids)->whereIn('status', ['blocked', 'quarantine', 'damaged'])->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->selectRaw('product_id, SUM(quantity) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $onHand = $this->companyScope(InventoryMovement::query(), $companyId)->whereIn('product_id', $ids)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->selectRaw("product_id, COALESCE(SUM(CASE WHEN movement_type IN ('opening','receipt','transfer_in','adjustment_in','return_in','quarantine_out','release') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in') THEN -quantity ELSE 0 END), 0) AS quantity")->groupBy('product_id')->pluck('quantity', 'product_id');
        $available = app(InventoryAvailabilityService::class)->availableMany($products, true, $locationId, $companyId);

        return response()->json($products->map(fn (Product $product): array => [
            'product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name,
            'product_type' => $product->product_type ?: 'stock', 'lifecycle_status' => $product->lifecycle_status ?: 'active',
            'can_purchase' => (bool) ($product->can_purchase ?? true), 'can_sell' => (bool) ($product->can_sell ?? true), 'is_stock_item' => (bool) ($product->is_stock_item ?? true),
            'weight_kg' => $product->weight_kg, 'length_m' => $product->length_m, 'width_m' => $product->width_m, 'height_m' => $product->height_m,
            'on_hand' => $onHand->has($product->id) ? (float) $onHand[$product->id] : (float) ($locationId === null ? $product->quantity : 0), 'reserved' => (float) ($reserved[$product->id] ?? 0),
            'quality_hold' => (float) ($quality[$product->id] ?? 0), 'available' => (float) ($available[$product->id] ?? 0),
        ])->values());
    }

    public function valuation(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'], 'category_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'], 'batch_id' => ['nullable', 'integer'],
            'as_of' => ['nullable', 'date'], 'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (!empty($data['location_id']) && !$this->companyScope(InventoryLocation::query(), $companyId)->whereKey($data['location_id'])->exists()) abort(422, 'Location is not authorized for this company.');

        $asOf = $data['as_of'] ?? null;
        $valueExpression = $asOf
            ? 'COALESCE(SUM(GREATEST(layers.original_quantity - (SELECT COALESCE(SUM(consumptions.quantity), 0) FROM inventory_cost_consumptions consumptions LEFT JOIN inventory_movements consumption_movements ON consumption_movements.id = consumptions.movement_id WHERE consumptions.cost_layer_id = layers.id AND ((consumptions.movement_id IS NOT NULL AND DATE(consumption_movements.posted_at) <= ?) OR (consumptions.movement_id IS NULL AND DATE(consumptions.created_at) <= ?))), 0) * layers.unit_cost), 0)'
            : 'COALESCE(SUM(layers.remaining_quantity * layers.unit_cost), 0)';
        $quantityExpression = $asOf
            ? 'COALESCE(SUM(GREATEST(layers.original_quantity - (SELECT COALESCE(SUM(consumptions.quantity), 0) FROM inventory_cost_consumptions consumptions LEFT JOIN inventory_movements consumption_movements ON consumption_movements.id = consumptions.movement_id WHERE consumptions.cost_layer_id = layers.id AND ((consumptions.movement_id IS NOT NULL AND DATE(consumption_movements.posted_at) <= ?) OR (consumptions.movement_id IS NULL AND DATE(consumptions.created_at) <= ?))), 0)), 0)'
            : 'COALESCE(SUM(layers.remaining_quantity), 0)';
        $layers = function ($query) use ($data, $asOf): void {
            $query->from('inventory_cost_layers as layers')->whereColumn('layers.product_id', 'products.id')
                ->when($asOf, fn ($q) => $q->whereDate('layers.received_at', '<=', $asOf))
                ->when(!$asOf, fn ($q) => $q->where('layers.remaining_quantity', '>', 0))
                ->when($data['location_id'] ?? null, fn ($q, $id) => $q->where('layers.location_id', $id))
                ->when($data['batch_id'] ?? null, fn ($q, $id) => $q->where('layers.batch_id', $id));
        };
        $valuation = Product::with('category')->select(['products.id', 'products.name', 'products.sku', 'products.updated_at'])
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($data['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($data['updated_since'] ?? null, function ($q, $date) use ($data): void {
                $q->where(function ($scope) use ($date, $data): void {
                    $scope->where('products.updated_at', '>=', $date)->orWhereExists(function ($layersQuery) use ($date, $data): void {
                        $layersQuery->selectRaw('1')->from('inventory_cost_layers as changed_layers')->whereColumn('changed_layers.product_id', 'products.id')->where('changed_layers.updated_at', '>=', $date)
                            ->when($data['location_id'] ?? null, fn ($layerQuery, $id) => $layerQuery->where('changed_layers.location_id', $id))
                            ->when($data['batch_id'] ?? null, fn ($layerQuery, $id) => $layerQuery->where('changed_layers.batch_id', $id));
                    });
                });
            })
            ->selectSub(function ($q) use ($layers, $valueExpression, $asOf): void { $layers($q); $q->selectRaw($valueExpression, $asOf ? [$asOf, $asOf] : []); }, 'ledger_value')
            ->selectSub(function ($q) use ($layers, $quantityExpression, $asOf): void { $layers($q); $q->selectRaw($quantityExpression, $asOf ? [$asOf, $asOf] : []); }, 'valuation_quantity')
            ->whereExists($layers)->orderBy('products.updated_at')->orderBy('products.id');

        return app(IntegrationCursorService::class)->paginate($valuation, $request, 'inventory.valuation', (int) ($data['per_page'] ?? 50));
    }

    public function movements(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'], 'serial_id' => ['nullable', 'integer'],
            'movement_type' => ['nullable', 'string', 'max:50'],
            'posted_from' => ['nullable', 'date'],
            'posted_to' => ['nullable', 'date', 'after_or_equal:posted_from'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $movements = $this->companyScope(InventoryMovement::query(), $companyId)
            ->with(['product:id,name,sku', 'location:id,code,name', 'batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until', 'serial:id,product_id,batch_id,serial_no,status,warranty_until', 'creator:id,name,email', 'allocations.batch:id,product_id,batch_no,lot_no,manufacturing_date,expiry_date,best_before_date,warranty_until', 'allocations.serial:id,product_id,batch_id,serial_no,status,warranty_until'])
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->when($data['batch_id'] ?? null, fn ($query, $id) => $query->where(fn ($nested) => $nested->where('batch_id', $id)->orWhereHas('allocations', fn ($allocation) => $allocation->where('batch_id', $id))))
            ->when($data['serial_id'] ?? null, fn ($query, $id) => $query->where('serial_id', $id))
            ->when($data['movement_type'] ?? null, fn ($query, $type) => $query->where('movement_type', $type))
            ->when($data['posted_from'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '>=', $date))
            ->when($data['posted_to'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '<=', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($movements, $request, 'inventory.movements', (int) ($data['per_page'] ?? 50));
    }

    public function counts(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,submitted,approved,rejected'],
            'location_id' => ['nullable', 'integer'], 'recount_required' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if (!empty($data['location_id']) && !$this->companyScope(InventoryLocation::query(), $companyId)->whereKey($data['location_id'])->exists()) abort(422, 'Location is not authorized for this company.');
        $counts = $this->companyScope(StockCount::with(['location:id,code,name', 'creator:id,name,email', 'approver:id,name,email', 'recountRequester:id,name,email', 'lines.product:id,name,sku', 'lines.recounter:id,name,email']), $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when(array_key_exists('location_id', $data), fn ($query) => $query->where('location_id', $data['location_id']))
            ->when(array_key_exists('recount_required', $data), fn ($query) => $query->where('recount_required', (bool) $data['recount_required']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($counts, $request, 'inventory.counts', (int) ($data['per_page'] ?? 50));
    }

    public function documents(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'document_type' => ['nullable', 'in:receipt,issue'],
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $documents = $this->companyScope(InventoryDocument::with(['location', 'department', 'costCenter', 'lines.product', 'lines.department', 'lines.costCenter']), $companyId)
            ->when($data['document_type'] ?? null, fn ($query, $type) => $query->where('document_type', $type))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($documents, $request, 'inventory.documents', (int) ($data['per_page'] ?? 50));
    }

    public function createDocument(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('inventory_documents', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'document_no' => ['nullable', 'string', 'max:100', Rule::unique('inventory_documents', 'document_no')->where(fn ($query) => $query->where('company_id', $companyId))],
            'document_type' => ['required', 'in:receipt,issue'], 'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)],
            'department_id' => ['nullable', 'integer', $owned('departments')], 'cost_center_id' => ['nullable', 'integer', $owned('cost_centers')],
            'date' => ['required', 'date'], 'description' => ['required', 'string', 'max:2000'], 'inspection_required' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer', $owned('products')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.department_id' => ['nullable', 'integer', $owned('departments')], 'lines.*.cost_center_id' => ['nullable', 'integer', $owned('cost_centers')],
            'lines.*.batch_no' => ['nullable', 'string', 'max:100'], 'lines.*.serial_numbers' => ['nullable', 'string', 'max:5000'],
            'lines.*.manufacturing_date' => ['nullable', 'date'], 'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.best_before_date' => ['nullable', 'date'], 'lines.*.warranty_until' => ['nullable', 'date'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(InventoryDocument::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['location', 'department', 'costCenter', 'lines.product', 'lines.department', 'lines.costCenter']), 'status' => 'duplicate_ignored']);
        }
        $document = DB::transaction(function () use ($data, $companyId, $request): InventoryDocument {
            $document = InventoryDocument::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'document_no' => $data['document_no'] ?: strtoupper($data['document_type']).'-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'document_type' => $data['document_type'], 'location_id' => $data['location_id'] ?? null,
                'department_id' => $data['department_id'] ?? null, 'cost_center_id' => $data['cost_center_id'] ?? null,
                'date' => $data['date'], 'description' => $data['description'], 'inspection_status' => ($data['document_type'] === 'receipt' && !empty($data['inspection_required'])) ? 'pending' : 'not_required',
                'created_by' => $request->user()?->id, 'status' => 'pending',
            ]);
            foreach ($data['lines'] as $line) InventoryDocumentLine::create([
                'inventory_document_id' => $document->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'],
                'department_id' => $line['department_id'] ?? null, 'cost_center_id' => $line['cost_center_id'] ?? null,
                'unit_cost' => $line['unit_cost'] ?? null, 'batch_no' => $line['batch_no'] ?? null, 'serial_numbers' => $line['serial_numbers'] ?? null,
                'manufacturing_date' => $line['manufacturing_date'] ?? null, 'expiry_date' => $line['expiry_date'] ?? null,
                'best_before_date' => $line['best_before_date'] ?? null, 'warranty_until' => $line['warranty_until'] ?? null,
            ]);
            app(AuditService::class)->record('inventory_document.created', $document, null, $document->toArray() + ['api' => true]);
            return $document;
        });
        return response()->json(['data' => $document->load(['location', 'department', 'costCenter', 'lines.product', 'lines.department', 'lines.costCenter']), 'status' => 'pending_approval'], 201);
    }

    public function approveDocument(int $id): JsonResponse
    {
        app(ApprovalGuard::class)->assertBeforeTransaction(InventoryDocument::class, $id);
        $document = $this->companyScope(InventoryDocument::query(), auth()->user()?->company_id)->findOrFail($id);
        return response()->json(['data' => app(InventoryDocumentApprovalService::class)->approve($document, auth()->user()), 'status' => 'approved']);
    }

    public function rejectDocument(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $document = $this->companyScope(InventoryDocument::query(), auth()->user()?->company_id)->findOrFail($id);
        return response()->json(['data' => app(InventoryDocumentApprovalService::class)->reject($document, $data['reason'], auth()->user()), 'status' => 'rejected']);
    }

    public function inspectDocument(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        $document = $this->companyScope(InventoryDocument::query(), auth()->user()?->company_id)->findOrFail($id);
        return response()->json(['data' => app(InventoryDocumentApprovalService::class)->inspect($document, $data['inspection_status'], $data['inspection_notes'], auth()->user()), 'status' => $data['inspection_status']]);
    }

    public function transfers(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected,in_transit,partially_received,received,cancelled'],
            'variance_status' => ['nullable', 'in:pending,accepted,waived'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $transfers = $this->companyScope(InventoryTransfer::with([
            'creator:id,name,email', 'approver:id,name,email', 'dispatcher:id,name,email', 'receiver:id,name,email',
            'varianceResolver:id,name,email', 'lines.product:id,name,sku',
            'lines.sourceLocation:id,code,name', 'lines.destinationLocation:id,code,name',
            'lines.transferSerials.serial',
        ]), $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['variance_status'] ?? null, fn ($query, $status) => $query->where('variance_status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate($transfers, $request, 'inventory.transfers', (int) ($data['per_page'] ?? 50));
    }

    public function createAdjustment(Request $request): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $data = $request->validate([
            'adjustment_no' => ['nullable', 'string', 'max:100', Rule::unique('inventory_adjustments', 'adjustment_no')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('inventory_adjustments', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'date' => ['required', 'date'], 'reason_code' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', $owned('products')],
            'lines.*.location_id' => ['nullable', 'integer', $locationScope],
            'lines.*.direction' => ['required', 'in:in,out'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        foreach ($data['lines'] as $line) {
            if (!empty($line['location_id']) && !$this->companyScope(InventoryLocation::query(), $companyId)->whereKey($line['location_id'])->exists()) abort(422, 'A line location is not authorized for this company.');
        }
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(InventoryAdjustment::query(), $companyId)->where('external_reference', $data['external_reference'])->with('lines')->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $adjustment = DB::transaction(function () use ($data): InventoryAdjustment {
            $adjustment = InventoryAdjustment::create([
                'company_id' => auth()->user()?->company_id,
                'adjustment_no' => $data['adjustment_no'] ?? 'API-ADJ-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'external_reference' => $data['external_reference'] ?? null,
                'date' => $data['date'], 'reason_code' => $data['reason_code'], 'description' => $data['description'] ?? null,
                'created_by' => auth()->id(),
            ]);
            foreach ($data['lines'] as $line) InventoryAdjustmentLine::create($line + ['adjustment_id' => $adjustment->id]);
            app(AuditService::class)->record('inventory_adjustment.created', $adjustment, null, $adjustment->toArray());
            return $adjustment->load('lines');
        });
        return response()->json(['data' => $adjustment, 'status' => 'pending_approval'], 201);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'reason_code' => ['nullable', 'string', 'max:100'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $adjustments = $this->companyScope(InventoryAdjustment::with([
            'creator:id,name,email', 'approver:id,name,email',
            'lines.product:id,name,sku', 'lines.location:id,code,name',
        ]), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['reason_code'] ?? null, fn ($query, $reason) => $query->where('reason_code', $reason))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate(
            $adjustments,
            $request,
            'inventory.adjustments',
            (int) ($data['per_page'] ?? 50)
        );
    }

    public function adjustmentReconciliation(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'in:approved,rejected,pending'], 'tolerance' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);
        $tolerance = (float) ($data['tolerance'] ?? 0.000001);
        $adjustments = $this->companyScope(InventoryAdjustment::with('lines'), $companyId)
            ->whereBetween('date', [$data['from'], $data['to']])
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('date')->orderBy('id')->get();
        $rows = $adjustments->map(function (InventoryAdjustment $adjustment) use ($tolerance): array {
            $movements = InventoryMovement::where('company_id', $adjustment->company_id)->where('reference_type', $adjustment->getMorphClass())->where('reference_id', $adjustment->id)->get(['quantity', 'unit_cost', 'movement_type']);
            $expected = (float) $movements->sum(fn (InventoryMovement $movement): float => (float) $movement->quantity * (float) ($movement->unit_cost ?? 0));
            $journals = JournalEntry::where('company_id', $adjustment->company_id)->where('source_type', $adjustment->getMorphClass())->where('source_id', $adjustment->id)->where('status', 'posted')->with('lines')->get();
            $posted = (float) $journals->sum(fn (JournalEntry $journal): float => (float) $journal->lines->sum('debit'));
            $variance = round($expected - $posted, 6);
            return ['adjustment_id' => $adjustment->id, 'adjustment_no' => $adjustment->adjustment_no, 'date' => optional($adjustment->date)->toDateString(), 'status' => $adjustment->status, 'reason_code' => $adjustment->reason_code, 'line_count' => $adjustment->lines->count(), 'movement_count' => $movements->count(), 'expected_value' => round($expected, 6), 'posted_value' => round($posted, 6), 'variance' => $variance, 'journal_count' => $journals->count(), 'reconciliation_status' => $journals->isEmpty() && $expected > $tolerance ? 'missing_journal' : (abs($variance) > $tolerance ? 'variance' : 'reconciled')];
        })->values();
        return response()->json(['data' => $rows, 'summary' => ['adjustments' => $rows->count(), 'expected_value' => round((float) $rows->sum('expected_value'), 6), 'posted_value' => round((float) $rows->sum('posted_value'), 6), 'variance' => round((float) $rows->sum('variance'), 6), 'variance_count' => $rows->where('reconciliation_status', 'variance')->count(), 'missing_journal_count' => $rows->where('reconciliation_status', 'missing_journal')->count()], 'meta' => ['from' => $data['from'], 'to' => $data['to'], 'tolerance' => $tolerance]]);
    }

    public function approveAdjustment(int $id): JsonResponse
    {
        try {
            $adjustment = app(InventoryAdjustmentApprovalService::class)->approve(
                InventoryAdjustment::query()->findOrFail($id),
                auth()->user()?->company_id,
                auth()->id()
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $adjustment->load('lines.product', 'lines.location'), 'status' => $adjustment->status]);
    }

    public function rejectAdjustment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $adjustment = app(InventoryAdjustmentApprovalService::class)->reject(
                InventoryAdjustment::query()->findOrFail($id),
                auth()->user()?->company_id,
                auth()->id(),
                $data['rejection_reason']
            );
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $adjustment->load('lines.product', 'lines.location'), 'status' => $adjustment->status]);
    }

    public function salesOrders(Request $request): JsonResponse
    {
        $orders = $this->companyScope(SalesOrder::with(['customer', 'promotion', 'lines.product']), $request->user()?->company_id)
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->has('updated_since'), fn ($query) => $query->where('updated_at', '>=', $request->date('updated_since')))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($orders, $request, 'sales.orders', (int) $request->input('per_page', 50));
    }

    public function purchaseOrders(Request $request): JsonResponse
    {
        $orders = $this->companyScope(PurchaseOrder::with(['supplier', 'lines.product']), $request->user()?->company_id)
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->has('updated_since'), fn ($query) => $query->where('updated_at', '>=', $request->date('updated_since')))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($orders, $request, 'purchasing.orders', (int) $request->input('per_page', 50));
    }

    public function goodsReceipts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'inspection_status' => ['nullable', 'in:not_required,pending,passed,failed'],
            'discrepancy_status' => ['nullable', 'in:none,open,accepted'],
            'is_final_delivery' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $receipts = $this->companyScope(GoodsReceipt::with(['purchaseOrder.supplier', 'location', 'lines.product']), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['inspection_status'] ?? null, fn ($query, $status) => $query->where('inspection_status', $status))
            ->when(array_key_exists('discrepancy_status', $data) && $data['discrepancy_status'] !== null, fn ($query) => $query->where('discrepancy_status', $data['discrepancy_status']))
            ->when(array_key_exists('is_final_delivery', $data) && $data['is_final_delivery'] !== null, fn ($query) => $query->where('is_final_delivery', $data['is_final_delivery']))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($receipts, $request, 'purchasing.goods-receipts', (int) ($data['per_page'] ?? 50));
    }

    public function deliveries(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'order_status' => ['nullable', 'in:draft,submitted,approved,partially_delivered,delivered,cancelled,rejected'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $deliveries = $this->companyScope(Delivery::with(['salesOrder.customer', 'location', 'lines.product', 'operations']), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where(fn ($scope) => $scope->where('fulfillment_status', $status)->orWhere('status', $status)))
            ->when($data['order_status'] ?? null, fn ($query, $status) => $query->whereHas('salesOrder', fn ($order) => $order->where('status', $status)))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($deliveries, $request, 'sales.deliveries', (int) ($data['per_page'] ?? 50));
    }

    public function purchaseInvoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $invoices = $this->companyScope(PurchaseInvoice::with(['supplier', 'purchaseOrder', 'lines.product']), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($invoices, $request, 'purchasing.invoices', (int) ($data['per_page'] ?? 50));
    }

    public function createSalesInvoice(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            // Repeated external references are handled below so integrations receive
            // a deterministic duplicate_ignored response instead of a validation error.
            'external_reference' => ['nullable', 'string', 'max:150'],
            'customer_id' => ['required', 'integer', $owned('customers')], 'store_id' => ['nullable', 'integer', $owned('stores')],
            'date' => ['required', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'billing_address' => ['nullable', 'string', 'max:2000'], 'shipping_address' => ['nullable', 'string', 'max:2000'],
            'billing_contact_id' => ['nullable', 'integer'], 'shipping_contact_id' => ['nullable', 'integer'],
            'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_mode' => ['nullable', 'in:exclusive,inclusive'], 'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'promotion_code' => ['nullable', 'string', 'max:80'], 'promotion_codes' => ['nullable', 'array', 'max:10'], 'promotion_codes.*' => ['string', 'max:80', 'distinct'],
            'paid_status' => ['required', 'in:full_paid,full_due,partial_paid'], 'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', $owned('products')], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.batch_no' => ['nullable', 'string', 'max:100'],
            'lines.*.serial_numbers' => ['nullable', 'string', 'max:5000'],
        ]);
        if ($data['paid_status'] === 'partial_paid' && (!array_key_exists('paid_amount', $data) || !is_numeric($data['paid_amount']))) {
            abort(422, 'paid_amount is required for partial payments.');
        }
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(Invoice::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('customer', 'store', 'invoice_details.product', 'payment'), 'status' => 'duplicate_ignored']);
        }
        $customer = $this->companyScope(Customer::query(), $companyId)->findOrFail($data['customer_id']);
        $store = !empty($data['store_id']) ? $this->companyScope(Store::query(), $companyId)->findOrFail($data['store_id']) : null;
        $billingContact = !empty($data['billing_contact_id'])
            ? CustomerContact::where('customer_id', $customer->id)->whereKey($data['billing_contact_id'])->where('contact_type', 'billing')->firstOrFail()
            : null;
        $shippingContact = !empty($data['shipping_contact_id'])
            ? CustomerContact::where('customer_id', $customer->id)->whereKey($data['shipping_contact_id'])->where('contact_type', 'shipping')->firstOrFail()
            : null;
        $billingAddress = $billingContact ? $this->formatContactAddress($billingContact) : $customer->address;
        $shippingAddress = $shippingContact ? $this->formatContactAddress($shippingContact) : $customer->address;
        $currency = strtoupper($data['currency_code'] ?? ($request->user()?->company?->base_currency ?? 'USD'));
        $exchangeRate = $data['exchange_rate'] ?? app(CurrencyConversionService::class)->rate($currency, strtoupper($request->user()?->company?->base_currency ?? 'USD'), $data['date']);
        $taxMode = $data['tax_mode'] ?? app(\App\Services\ErpSettingService::class)->get('default_tax_mode', 'exclusive');
        $taxExempt = (bool) $customer->tax_exempt;
        $promotionCodes = $data['promotion_codes'] ?? (!empty($data['promotion_code']) ? [$data['promotion_code']] : []);
        $invoice = DB::transaction(function () use ($data, $companyId, $customer, $store, $currency, $exchangeRate, $taxMode, $taxExempt, $billingAddress, $shippingAddress, $request, $promotionCodes): Invoice {
            $invoice = Invoice::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'invoice_no' => app(NumberingSequenceService::class)->nextOrFallback('sales_invoice', 'INV-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'customer_id' => $customer->id, 'store_id' => $store?->id, 'date' => $data['date'],
                'due_date' => $data['due_date'] ?? \Carbon\CarbonImmutable::parse($data['date'])->addDays((int) ($customer->credit_days ?? 0))->toDateString(),
                'billing_address' => $data['billing_address'] ?? $billingAddress, 'shipping_address' => $data['shipping_address'] ?? $shippingAddress,
                'currency_code' => $currency, 'exchange_rate' => $exchangeRate, 'tax_mode' => $taxMode, 'tax_exempt' => $taxExempt, 'tax_jurisdiction' => $customer->tax_jurisdiction,
                'tax_exemption_number' => $taxExempt ? $customer->tax_exemption_number : null, 'description' => $data['description'] ?? null,
                'status' => 0, 'created_by' => $request->user()?->id,
            ]);
            $lineTotal = 0.0; $subtotal = 0.0; $taxTotal = 0.0; $lineRows = [];
            foreach ($data['lines'] as $line) {
                $product = $this->companyScope(Product::query(), $companyId)->findOrFail($line['product_id']);
                $lineTotalValue = (float) $line['quantity'] * (float) $line['unit_price'];
                $rate = $taxExempt ? 0.0 : app(TaxRateResolver::class)->rateFor($product, $data['date'], $customer->tax_jurisdiction);
                $taxResult = $taxMode === 'inclusive'
                    ? app(TaxCalculationService::class)->inclusive($lineTotalValue, $rate)
                    : ['net' => $lineTotalValue, 'tax' => app(TaxCalculationService::class)->exclusive($lineTotalValue, $rate)];
                $lineTotal += $lineTotalValue; $subtotal += (float) $taxResult['net']; $taxTotal += (float) $taxResult['tax'];
                $lineRows[] = [
                    'product_id' => $product->id, 'quantity' => (float) $line['quantity'], 'unit_price' => (float) $line['unit_price'],
                    'discount' => 0.0, 'category_id' => $product->category_id, 'batch_no' => $line['batch_no'] ?? null,
                    'serial_numbers' => $line['serial_numbers'] ?? null, 'tax_rate' => $rate, 'tax_amount' => (float) $taxResult['tax'],
                ];
            }
            $discount = (float) ($data['discount_amount'] ?? 0);
            $promotionResult = app(PromotionService::class)->applyCodesToLines($promotionCodes, $lineRows, $customer->id, $data['date']);
            $promotionDiscount = (float) collect($promotionResult['lines'])->sum('discount');
            $discount += $promotionDiscount;
            foreach ($promotionResult['lines'] as $line) {
                InvoiceDetail::create([
                    'date' => $data['date'], 'invoice_id' => $invoice->id, 'category_id' => $line['category_id'],
                    'product_id' => $line['product_id'], 'batch_no' => $line['batch_no'], 'serial_numbers' => $line['serial_numbers'],
                    'selling_qty' => $line['quantity'], 'unit_price' => $line['unit_price'], 'selling_price' => (float) $line['quantity'] * (float) $line['unit_price'],
                    'tax_rate' => $line['tax_rate'], 'tax_amount' => $line['tax_amount'], 'status' => 0,
                ]);
            }
            if ($discount > $lineTotal + 0.000001) throw new \RuntimeException('Discount cannot exceed the invoice subtotal.');
            $netSubtotal = max(0, $subtotal - $discount);
            $total = $taxMode === 'inclusive' ? $lineTotal - $discount : $netSubtotal + $taxTotal;
            $paid = match ($data['paid_status']) {
                'full_paid' => $total, 'partial_paid' => (float) $data['paid_amount'], default => 0.0,
            };
            if ($paid > $total + 0.000001) throw new \RuntimeException('Paid amount cannot exceed the invoice total.');
            $invoice->update(['subtotal_amount' => $netSubtotal, 'tax_amount' => $taxTotal, 'total_amount' => $total, 'promotion_id' => $promotionResult['promotion']?->id, 'promotion_ids' => collect($promotionResult['promotions'])->pluck('id')->values()->all()]);
            Payment::create([
                'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'currency_code' => $currency, 'exchange_rate' => $exchangeRate,
                'paid_status' => $data['paid_status'], 'discount_amount' => $discount, 'total_amount' => $total,
                'paid_amount' => $paid, 'due_amount' => $total - $paid, 'base_amount' => $paid * (float) ($exchangeRate ?: 1),
            ]);
            app(AuditService::class)->record('invoice.created', $invoice, null, $invoice->toArray());
            return $invoice;
        });
        return response()->json(['data' => $invoice->load('customer', 'store', 'invoice_details.product', 'payment'), 'status' => 'pending_approval'], 201);
    }

    public function approveSalesInvoice(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(Invoice::class, $id);
        try {
            $invoice = DB::transaction(function () use ($id): Invoice {
                $invoice = $this->companyScope(Invoice::with(['invoice_details', 'payment']), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ((int) $invoice->status !== 0) throw new \RuntimeException('Only pending invoices can be approved.');
                app(\App\Services\SalesDiscountPolicyService::class)->assertInvoiceCanApprove($invoice);
                app(\App\Services\ApprovalGuard::class)->assertDifferent($invoice);
                foreach ($invoice->invoice_details as $detail) {
                    $product = $this->companyScope(Product::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($detail->product_id);
                    app(ProductLifecycleService::class)->assertSellable($product);
                    if ($invoice->invoice_type === 'service') {
                        $detail->status = 1;
                        $detail->save();
                        continue;
                    }
                    if (app(InventoryAvailabilityService::class)->available($product, false, null, $invoice->company_id) < (float) $detail->selling_qty) {
                        throw new \RuntimeException('Insufficient stock for '.$product->name.'.');
                    }
                    $batch = $detail->batch_no ? InventoryBatch::where('product_id', $product->id)->where('batch_no', $detail->batch_no)->lockForUpdate()->first() : null;
                    if ($detail->batch_no && !$batch) throw new \RuntimeException('Batch '.$detail->batch_no.' was not found for '.$product->name.'.');
                    $issuedSerials = collect();
                    if ($product->tracking_type === 'serial') {
                        $serials = $detail->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $detail->serial_numbers)))) : [];
                        if ($serials && count($serials) !== (int) round((float) $detail->selling_qty)) throw new \RuntimeException('Serial count must equal quantity for '.$product->name.'.');
                        $issuedSerials = $serials ? app(SerialLifecycleService::class)->issueSpecific($product, $serials, null, $batch?->id) : app(SerialLifecycleService::class)->issue($product, (float) $detail->selling_qty, null, $batch?->id);
                    }
                    $product->quantity = (float) $product->quantity - (float) $detail->selling_qty;
                    $product->save();
                    if ($issuedSerials->isNotEmpty()) foreach ($issuedSerials as $serial) app(\App\Services\InventoryLedgerService::class)->post($product->id, 'issue', 1, (float) $detail->unit_price, null, $invoice, 'Approved sales issue', null, $batch?->id, $serial->id);
                    else app(\App\Services\InventoryLedgerService::class)->post($product->id, 'issue', (float) $detail->selling_qty, (float) $detail->unit_price, null, $invoice, 'Approved sales issue', null, $batch?->id);
                    $detail->batch_id = $batch?->id; $detail->status = 1; $detail->save();
                }
                $invoice->update(['status' => 1, 'updated_by' => auth()->id()]);
                foreach (($invoice->promotion_ids ?: ($invoice->promotion_id ? [$invoice->promotion_id] : [])) as $promotionId) {
                    $redeemed = app(PromotionService::class)->redeem((int) $promotionId);
                    app(AuditService::class)->record('promotion.redeemed', $redeemed, ['usage_count' => max(0, (int) $redeemed->usage_count - 1)], ['usage_count' => $redeemed->usage_count, 'invoice_id' => $invoice->id]);
                }
                app(AutomaticAccountingService::class)->postSalesInvoice($invoice);
                if ($payment = Payment::where('invoice_id', $invoice->id)->first()) app(AutomaticAccountingService::class)->postCustomerPayment($payment);
                app(AuditService::class)->record('invoice.approved', $invoice, ['status' => 0], ['status' => 1]);
                return $invoice->fresh();
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $invoice->load('customer', 'store', 'invoice_details.product', 'payment'), 'status' => 'approved']);
    }

    public function rejectSalesInvoice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $invoice = DB::transaction(function () use ($id, $data): Invoice {
                $invoice = $this->companyScope(Invoice::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ((int) $invoice->status !== 0) throw new \RuntimeException('Only pending invoices can be rejected.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($invoice);
                $before = $invoice->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
                $invoice->update(['status' => 2, 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
                app(AuditService::class)->record('invoice.rejected', $invoice, $before, $invoice->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
                return $invoice->fresh();
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $invoice->load('customer', 'store', 'invoice_details.product', 'payment'), 'status' => 'rejected']);
    }

    public function salesInvoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $invoices = $this->companyScope(Invoice::with(['customer', 'store', 'invoice_details.product', 'payment']), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($invoices, $request, 'sales.invoices', (int) ($data['per_page'] ?? 50));
    }

    public function reconciliationSnapshots(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:balanced,variance'],
            'as_of' => ['nullable', 'date'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $snapshots = $this->companyScope(InventoryReconciliationSnapshot::query(), $request->user()?->company_id)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['as_of'] ?? null, fn ($query, $date) => $query->whereDate('as_of_date', $date))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($snapshots, $request, 'inventory.reconciliation-snapshots', (int) ($data['per_page'] ?? 50));
    }

    public function costLayerAdjustments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'batch_id' => ['nullable', 'integer'], 'landed_cost_id' => ['nullable', 'integer'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for cost-layer adjustment synchronization.');
        $adjustments = InventoryCostLayerAdjustment::with(['product:id,name,sku', 'costLayer.location:id,code,name', 'costLayer.batch:id,batch_no,lot_no', 'landedCost:id,cost_no,external_reference,status'])
            ->whereHas('landedCost', fn ($query) => $query->where('company_id', $companyId))
            ->when($data['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($data['location_id'] ?? null, fn ($query, $id) => $query->whereHas('costLayer', fn ($layer) => $layer->where('location_id', $id)))
            ->when($data['batch_id'] ?? null, fn ($query, $id) => $query->whereHas('costLayer', fn ($layer) => $layer->where('batch_id', $id)))
            ->when($data['landed_cost_id'] ?? null, fn ($query, $id) => $query->where('landed_cost_id', $id))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($adjustments, $request, 'inventory.cost-layer-adjustments', (int) ($data['per_page'] ?? 50));
    }

    public function salesReturns(Request $request): JsonResponse
    {
        return $this->returnsFeed($request, 'sales', 'sales.returns');
    }

    public function purchaseReturns(Request $request): JsonResponse
    {
        return $this->returnsFeed($request, 'purchase', 'purchasing.returns');
    }

    private function returnsFeed(Request $request, string $type, string $feed): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $returns = $this->companyScope(InventoryReturn::with(['customer', 'supplier', 'location', 'sourceInvoice', 'sourceGoodsReceipt', 'lines.product']), $request->user()?->company_id)
            ->where('return_type', $type)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($returns, $request, $feed, (int) ($data['per_page'] ?? 50));
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    private function formatContactAddress(CustomerContact $contact): string
    {
        return implode(', ', array_filter([$contact->line1, $contact->line2, $contact->city, $contact->state, $contact->postal_code, $contact->country]));
    }
}
