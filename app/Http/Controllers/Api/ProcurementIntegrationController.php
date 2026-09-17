<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Branch;
use App\Models\InventoryBatch;
use App\Models\InventorySerial;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\CurrencyConversionService;
use App\Services\InventoryLedgerService;
use App\Services\NumberingSequenceService;
use App\Services\SupplierProductPriceService;
use App\Services\PurchaseInvoiceService;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\LandedCost;
use App\Services\TaxCalculationService;
use App\Services\TaxRateResolver;
use App\Services\UomConversionService;
use App\Services\ProductLifecycleService;
use App\Services\LandedCostService;
use App\Services\ApprovalGuard;
use App\Services\IntegrationCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProcurementIntegrationController extends Controller
{
    public function supplierPerformance(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'supplier_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        abort_unless($request->user()?->company_id, 403, 'A company is required for supplier performance.');
        $from = $data['from'] ?? now()->subYear()->toDateString(); $to = $data['to'] ?? now()->toDateString();
        $orders = PurchaseOrder::with(['supplier', 'lines', 'receipts.lines', 'receipts.purchaseOrder'])->whereIn('status', ['approved', 'partially_received', 'received'])->whereBetween('date', [$from, $to])->when($data['supplier_id'] ?? null, fn ($q, $id) => $q->where('supplier_id', $id))->get();
        $invoices = PurchaseInvoice::with('lines')->where('status', 'approved')->whereBetween('invoice_date', [$from, $to])->whereIn('purchase_order_id', $orders->pluck('id'))->get()->groupBy('purchase_order_id');
        $rows = $orders->groupBy('supplier_id')->map(function ($supplierOrders, $supplierId) use ($invoices): array {
            $supplier = $supplierOrders->first()->supplier; $ordered = (float) $supplierOrders->sum(fn ($order) => $order->lines->sum('ordered_qty')); $received = (float) $supplierOrders->sum(fn ($order) => $order->lines->sum('received_qty')); $receipts = $supplierOrders->flatMap->receipts; $onTime = $receipts->filter(fn ($receipt) => !$receipt->purchaseOrder?->expected_date || $receipt->date <= $receipt->purchaseOrder->expected_date)->count(); $orderedValue = (float) $supplierOrders->sum(fn ($order) => $order->lines->sum(fn ($line) => (float) $line->ordered_qty * (float) $line->unit_price)); $supplierInvoices = $supplierOrders->flatMap(fn ($order) => $invoices->get($order->id, collect())); $invoicedQty = (float) $supplierInvoices->sum(fn ($invoice) => $invoice->lines->sum('quantity')); $invoicedValue = (float) $supplierInvoices->sum(fn ($invoice) => $invoice->lines->sum(fn ($line) => (float) $line->quantity * (float) $line->unit_price)); $orderedUnitCost = $ordered > 0 ? $orderedValue / $ordered : 0; $invoicedUnitCost = $invoicedQty > 0 ? $invoicedValue / $invoicedQty : 0;
            $inspectedReceipts = $receipts->filter(fn ($receipt) => in_array($receipt->inspection_status, ['passed', 'failed'], true));
            $failedReceipts = $inspectedReceipts->where('inspection_status', 'failed');
            $rejectedQuantity = (float) $failedReceipts->sum(fn ($receipt) => $receipt->lines->sum('received_qty'));
            return ['supplier_id' => (int) $supplierId, 'supplier' => $supplier, 'orders' => $supplierOrders->count(), 'ordered' => $ordered, 'received' => $received, 'fill_rate' => $ordered > 0 ? ($received / $ordered) * 100 : 0, 'receipts' => $receipts->count(), 'on_time_rate' => $receipts->count() > 0 ? ($onTime / $receipts->count()) * 100 : 0, 'inspected_receipts' => $inspectedReceipts->count(), 'failed_receipts' => $failedReceipts->count(), 'quality_pass_rate' => $inspectedReceipts->count() > 0 ? (($inspectedReceipts->count() - $failedReceipts->count()) / $inspectedReceipts->count()) * 100 : null, 'rejected_quantity' => $rejectedQuantity, 'quality_rejection_rate' => $received > 0 ? ($rejectedQuantity / $received) * 100 : 0, 'ordered_value' => $orderedValue, 'invoiced_value' => $invoicedValue, 'price_variance' => $orderedUnitCost > 0 && $invoicedQty > 0 ? (($invoicedUnitCost - $orderedUnitCost) / $orderedUnitCost) * 100 : 0];
        })->values()->map(function (array $row): array {
            $row['supplier_score'] = app(\App\Services\SupplierPerformanceScoringService::class)->score($row);
            return $row;
        });
        $page = max(1, $request->integer('page', 1)); $perPage = (int) ($data['per_page'] ?? 50);
        $inspected = (int) $rows->sum('inspected_receipts'); $failed = (int) $rows->sum('failed_receipts'); $received = (float) $rows->sum('received'); $rejected = (float) $rows->sum('rejected_quantity');
        return response()->json(['data' => $rows->forPage($page, $perPage)->values(), 'summary' => ['orders' => (int) $rows->sum('orders'), 'ordered' => (float) $rows->sum('ordered'), 'received' => $received, 'ordered_value' => (float) $rows->sum('ordered_value'), 'invoiced_value' => (float) $rows->sum('invoiced_value'), 'inspected_receipts' => $inspected, 'failed_receipts' => $failed, 'quality_pass_rate' => $inspected > 0 ? (($inspected - $failed) / $inspected) * 100 : null, 'rejected_quantity' => $rejected, 'quality_rejection_rate' => $received > 0 ? ($rejected / $received) * 100 : 0, 'supplier_score' => $rows->count() > 0 ? round((float) $rows->avg('supplier_score'), 2) : null], 'meta' => ['from' => $from, 'to' => $to, 'current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]]);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('purchase_invoices', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'invoice_date' => ['required', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency_code' => ['nullable', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'tax_mode' => ['nullable', 'in:exclusive,inclusive'], 'description' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_order_line_id' => ['required', 'integer'],
            'lines.*.goods_receipt_line_id' => ['nullable', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = PurchaseInvoice::where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('supplier', 'lines.product'), 'status' => 'duplicate_ignored']);
        }
        $order = PurchaseOrder::with('lines.product')->findOrFail($data['purchase_order_id']);
        if (!in_array($order->status, ['approved', 'partially_received', 'received'], true)) throw new \RuntimeException('Only approved or received purchase orders can be invoiced.');
        $supplier = $order->supplier;
        $currency = strtoupper($data['currency_code'] ?? ($order->currency_code ?: ($request->user()?->company?->base_currency ?? 'USD')));
        $exchangeRate = $data['exchange_rate'] ?? app(CurrencyConversionService::class)->rate($currency, strtoupper($request->user()?->company?->base_currency ?? 'USD'), $data['invoice_date']);
        $taxExempt = (bool) $supplier->tax_exempt;
        $invoice = DB::transaction(function () use ($data, $companyId, $order, $supplier, $currency, $exchangeRate, $taxExempt, $request): PurchaseInvoice {
            $taxMode = $data['tax_mode'] ?? app(\App\Services\ErpSettingService::class)->get('default_tax_mode', 'exclusive');
            $invoice = PurchaseInvoice::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'invoice_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_invoice', 'PINV-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'supplier_id' => $supplier->id, 'purchase_order_id' => $order->id, 'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? \Carbon\CarbonImmutable::parse($data['invoice_date'])->addDays((int) $supplier->payment_terms_days)->toDateString(), 'currency_code' => $currency, 'exchange_rate' => $exchangeRate,
                'tax_mode' => $taxMode, 'tax_exempt' => $taxExempt, 'tax_exemption_number' => $taxExempt ? $supplier->tax_exemption_number : null, 'tax_jurisdiction' => $supplier->tax_jurisdiction,
                'description' => $data['description'] ?? null, 'created_by' => $request->user()?->id,
            ]);
            $subtotal = 0; $tax = 0;
            foreach ($data['lines'] as $input) {
                $poLine = $order->lines->firstWhere('id', $input['purchase_order_line_id']);
                if (!$poLine) throw new \RuntimeException('Each invoice line must belong to the purchase order.');
                if (!empty($input['goods_receipt_line_id'])) {
                    $receiptLine = GoodsReceiptLine::with('goodsReceipt')->find($input['goods_receipt_line_id']);
                    if (!$receiptLine || (int) $receiptLine->purchase_order_line_id !== (int) $poLine->id || $receiptLine->goodsReceipt?->status !== 'approved') {
                        throw new \RuntimeException('Each linked goods-receipt line must belong to this purchase order and be approved.');
                    }
                }
                $quantity = (float) $input['quantity']; $unitPrice = (float) $input['unit_price']; $lineSubtotal = $quantity * $unitPrice;
                $rate = $taxExempt ? 0 : (isset($input['tax_rate']) ? (float) $input['tax_rate'] : app(TaxRateResolver::class)->rateFor($poLine->product, $data['invoice_date'], $supplier->tax_jurisdiction));
                $taxResult = $taxMode === 'inclusive' ? app(TaxCalculationService::class)->inclusive($lineSubtotal, $rate) : ['net' => $lineSubtotal, 'tax' => app(TaxCalculationService::class)->exclusive($lineSubtotal, $rate)];
                $lineTax = (float) $taxResult['tax']; $subtotal += (float) $taxResult['net']; $tax += $lineTax;
                PurchaseInvoiceLine::create(['purchase_invoice_id' => $invoice->id, 'purchase_order_line_id' => $poLine->id, 'goods_receipt_line_id' => $input['goods_receipt_line_id'] ?? null, 'product_id' => $poLine->product_id, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'tax_rate' => $rate, 'tax_amount' => $lineTax, 'line_total' => $lineSubtotal + ($taxMode === 'inclusive' ? 0 : $lineTax)]);
            }
            $invoice->update(['subtotal_amount' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $subtotal + $tax]);
            app(AuditService::class)->record('purchase_invoice.created', $invoice, null, $invoice->toArray());
            return $invoice;
        });
        return response()->json(['data' => $invoice->load('supplier', 'purchaseOrder', 'lines.product'), 'status' => 'pending_approval'], 201);
    }

    public function approveInvoice(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(PurchaseInvoice::class, $id);
        $invoice = DB::transaction(function () use ($id): PurchaseInvoice {
            $invoice = PurchaseInvoice::with(['lines.product', 'lines.purchaseOrderLine'])->lockForUpdate()->findOrFail($id);
            if ($invoice->status !== 'pending') throw new \RuntimeException('This invoice has already been processed.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($invoice);
            app(PurchaseInvoiceService::class)->approve($invoice);
            app(AuditService::class)->record('purchase_invoice.approved', $invoice, ['status' => 'pending'], ['status' => 'approved']);
            return $invoice->fresh();
        });
        return response()->json(['data' => $invoice->load('supplier', 'purchaseOrder', 'lines.product'), 'status' => $invoice->status]);
    }

    public function createReceipt(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $orderLineScope = Rule::exists('purchase_order_lines', 'id')->where('purchase_order_id', $request->input('purchase_order_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('goods_receipts', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'purchase_order_id' => ['required', 'integer', $owned('purchase_orders')],
            'location_id' => ['nullable', 'integer', $locationScope], 'date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'], 'inspection_required' => ['nullable', 'boolean'], 'is_final_delivery' => ['nullable', 'boolean'], 'discrepancy_reason' => ['nullable', 'string', 'max:3000', 'required_if:is_final_delivery,1'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.purchase_order_line_id' => ['required', 'integer', $orderLineScope],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.uom_id' => ['nullable', 'integer', $owned('units')],
            'lines.*.batch_no' => ['nullable', 'string', 'max:100'], 'lines.*.serial_numbers' => ['nullable', 'array'],
            'lines.*.serial_numbers.*' => ['string', 'max:150'], 'lines.*.manufacturing_date' => ['nullable', 'date'],
            'lines.*.expiry_date' => ['nullable', 'date'], 'lines.*.best_before_date' => ['nullable', 'date'],
            'lines.*.warranty_until' => ['nullable', 'date'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = GoodsReceipt::where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('purchaseOrder', 'lines.product'), 'status' => 'duplicate_ignored']);
        }
        $order = PurchaseOrder::with('lines.product')->findOrFail($data['purchase_order_id']);
        if ($order->receiving_closed) return response()->json(['message' => 'Receiving is closed for this purchase order.'], 422);
        if (!in_array($order->status, ['approved', 'partially_received'], true)) throw new \RuntimeException('Only approved or partially received purchase orders can receive stock.');
        $location = !empty($data['location_id']) ? InventoryLocation::findOrFail($data['location_id']) : null;
        $receipt = DB::transaction(function () use ($data, $companyId, $order, $location, $request): GoodsReceipt {
            $receipt = GoodsReceipt::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'grn_no' => app(NumberingSequenceService::class)->nextOrFallback('goods_receipt', 'GRN-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'purchase_order_id' => $order->id, 'location_id' => $location?->id, 'date' => $data['date'],
                'description' => $data['description'] ?? null, 'inspection_status' => !empty($data['inspection_required']) ? 'pending' : 'not_required', 'is_final_delivery' => (bool) ($data['is_final_delivery'] ?? false), 'discrepancy_reason' => $data['discrepancy_reason'] ?? null,
                'created_by' => $request->user()?->id,
            ]);
            foreach ($data['lines'] as $input) {
                $line = $order->lines->firstWhere('id', $input['purchase_order_line_id']);
                if (!$line) throw new \RuntimeException('Each receipt line must belong to the purchase order.');
                $product = $line->product; $enteredQuantity = (float) $input['quantity']; $uomId = $input['uom_id'] ?? null;
                $receivedQuantity = app(UomConversionService::class)->toStock($product, $enteredQuantity, $uomId ? (int) $uomId : null, 'purchase');
                $remaining = (float) $line->ordered_qty - (float) $line->received_qty;
                if ($receivedQuantity > $remaining) throw new \RuntimeException('Receipt exceeds remaining quantity for '.$product->name.'.');
                $conversion = $receivedQuantity / $enteredQuantity;
                GoodsReceiptLine::create([
                    'goods_receipt_id' => $receipt->id, 'purchase_order_line_id' => $line->id, 'product_id' => $product->id,
                    'uom_id' => $uomId, 'uom_quantity' => $enteredQuantity, 'received_qty' => $receivedQuantity,
                    'unit_cost' => (float) $input['unit_cost'] / max($conversion, 0.000001), 'batch_no' => $input['batch_no'] ?? null,
                    'serial_numbers' => !empty($input['serial_numbers']) ? implode("\n", $input['serial_numbers']) : null,
                    'manufacturing_date' => $input['manufacturing_date'] ?? null, 'expiry_date' => $input['expiry_date'] ?? null,
                    'best_before_date' => $input['best_before_date'] ?? null, 'warranty_until' => $input['warranty_until'] ?? null,
                ]);
            }
            app(AuditService::class)->record('goods_receipt.created', $receipt, null, $receipt->toArray());
            return $receipt;
        });
        return response()->json(['data' => $receipt->load('purchaseOrder', 'lines.product'), 'status' => 'pending_approval'], 201);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $owned = fn (string $table) => Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('purchase_orders', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'supplier_id' => ['required', 'integer', $owned('suppliers')], 'date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:date'], 'currency_code' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'description' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer', $owned('products')],
            'lines.*.uom_id' => ['nullable', 'integer', $owned('units')], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = PurchaseOrder::where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('supplier', 'lines.product'), 'status' => 'duplicate_ignored']);
        }
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $order = DB::transaction(function () use ($data, $companyId, $supplier, $request): PurchaseOrder {
            $order = PurchaseOrder::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null, 'supplier_id' => $supplier->id,
                'date' => $data['date'], 'expected_date' => $data['expected_date'] ?? null, 'description' => $data['description'] ?? null,
                'currency_code' => strtoupper($data['currency_code'] ?? ($request->user()?->company?->base_currency ?? 'USD')), 'exchange_rate' => $data['exchange_rate'] ?? 1,
                'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'status' => 'submitted', 'created_by' => $request->user()?->id,
            ]);
            foreach ($data['lines'] as $line) {
                $product = Product::findOrFail($line['product_id']); $enteredQuantity = (float) $line['quantity']; $uomId = $line['uom_id'] ?? null;
                app(ProductLifecycleService::class)->assertPurchasable($product);
                $stockQuantity = app(UomConversionService::class)->toStock($product, $enteredQuantity, $uomId ? (int) $uomId : null, 'purchase');
                $unitPrice = array_key_exists('unit_price', $line) && $line['unit_price'] !== null ? (float) $line['unit_price'] : 0;
                $agreement = app(SupplierProductPriceService::class)->bestFor($supplier, $product, $stockQuantity, now()->toDateString(), $order->currency_code);
                if ($agreement && $unitPrice <= 0) $unitPrice = (float) $agreement->unit_price;
                if ($uomId) $unitPrice /= max($stockQuantity / $enteredQuantity, 0.000001);
                PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $product->id, 'uom_id' => $uomId, 'uom_quantity' => $enteredQuantity, 'ordered_qty' => $stockQuantity, 'unit_price' => $unitPrice]);
            }
            app(AuditService::class)->record('purchase_order.created', $order, null, $order->toArray());
            return $order;
        });
        return response()->json(['data' => $order->load('supplier', 'lines.product'), 'status' => 'pending_approval'], 201);
    }

    public function approveOrder(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(PurchaseOrder::class, $id);
        $order = DB::transaction(function () use ($id): PurchaseOrder {
            $order = PurchaseOrder::with('lines.product')->lockForUpdate()->findOrFail($id);
            if ($order->status !== 'submitted') throw new \RuntimeException('Only submitted purchase orders can be approved.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($order);
            foreach ($order->lines as $line) app(ProductLifecycleService::class)->assertPurchasable($line->product);
            $order->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            app(AuditService::class)->record('purchase_order.approved', $order, ['status' => 'submitted'], ['status' => 'approved']);
            return $order->fresh();
        });
        return response()->json(['data' => $order->load('supplier', 'lines.product'), 'status' => $order->status]);
    }

    public function inspectReceipt(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        $receipt = DB::transaction(function () use ($data, $id): GoodsReceipt {
            $receipt = GoodsReceipt::lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'pending' || $receipt->inspection_status !== 'pending') throw new \RuntimeException('Only pending inspection receipts can be inspected.');
            $before = $receipt->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
            $receipt->update(['inspection_status' => $data['inspection_status'], 'inspection_notes' => $data['inspection_notes'], 'inspected_by' => auth()->id(), 'inspected_at' => now()]);
            app(AuditService::class)->record('goods_receipt.inspected', $receipt, $before, $receipt->fresh()->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']));
            return $receipt->fresh();
        });
        return response()->json(['data' => $receipt->load('purchaseOrder', 'lines.product'), 'status' => $receipt->inspection_status]);
    }

    public function rejectOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $order = DB::transaction(function () use ($data, $id): PurchaseOrder {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($id);
            if ($order->status !== 'submitted') throw new \RuntimeException('Only submitted purchase orders can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($order);
            $before = $order->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $order->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('purchase_order.rejected', $order, $before, $order->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $order->fresh();
        });
        return response()->json(['data' => $order->load('supplier', 'lines.product'), 'status' => $order->status]);
    }

    public function approveReceipt(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(GoodsReceipt::class, $id);
        $actorId = request()->user()?->id ?? auth()->id();
        $receipt = DB::transaction(function () use ($id, $actorId): GoodsReceipt {
            $receipt = GoodsReceipt::with(['lines.purchaseOrderLine', 'lines.product', 'purchaseOrder'])->lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'pending') throw new \RuntimeException('This receipt has already been processed.');
            if ($receipt->inspection_status !== 'not_required' && $receipt->inspection_status !== 'passed') throw new \RuntimeException('This receipt must pass inspection before stock can be posted.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($receipt);
            foreach ($receipt->lines as $line) {
                $product = Product::lockForUpdate()->findOrFail($line->product_id);
                $batch = null;
                if ($line->batch_no) {
                    $batch = InventoryBatch::firstOrCreate(['product_id' => $product->id, 'batch_no' => $line->batch_no], ['location_id' => $receipt->location_id, 'manufacturing_date' => $line->manufacturing_date, 'expiry_date' => $line->expiry_date, 'best_before_date' => $line->best_before_date, 'warranty_until' => $line->warranty_until]);
                }
                $receivedSerials = collect();
                if ($product->tracking_type === 'serial') {
                    $serials = $line->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $line->serial_numbers)))) : [];
                    if (count($serials) !== (int) round((float) $line->received_qty)) throw new \RuntimeException('Serial count must equal received quantity for '.$product->name.'.');
                    foreach ($serials as $serialNo) {
                        if (InventorySerial::where('product_id', $product->id)->where('serial_no', $serialNo)->lockForUpdate()->exists()) throw new \RuntimeException('Serial '.$serialNo.' already exists for '.$product->name.'.');
                        $receivedSerials->push(InventorySerial::create(['product_id' => $product->id, 'serial_no' => $serialNo, 'location_id' => $receipt->location_id, 'batch_id' => $batch?->id, 'warranty_until' => $line->warranty_until]));
                    }
                }
                $product->quantity = (float) $product->quantity + (float) $line->received_qty;
                $product->purchase_price = $line->unit_cost;
                $product->save();
                $poLine = PurchaseOrderLine::lockForUpdate()->findOrFail($line->purchase_order_line_id);
                $poLine->received_qty = (float) $poLine->received_qty + (float) $line->received_qty;
                $poLine->save();
                if ($receivedSerials->isNotEmpty()) {
                    foreach ($receivedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, 'receipt', 1, (float) $line->unit_cost, $receipt->location_id, $receipt, 'Approved goods receipt', null, $batch?->id, $serial->id);
                } else {
                    app(InventoryLedgerService::class)->post($product->id, 'receipt', (float) $line->received_qty, (float) $line->unit_cost, $receipt->location_id, $receipt, 'Approved goods receipt', null, $batch?->id);
                }
            }
            $order = PurchaseOrder::with('lines')->lockForUpdate()->findOrFail($receipt->purchase_order_id);
            $hasShortage = $order->lines->contains(fn ($line): bool => (float) $line->received_qty + 0.000001 < (float) $line->ordered_qty);
            $receipt->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now(), 'discrepancy_status' => $receipt->is_final_delivery && $hasShortage ? 'open' : 'none']);
            $isComplete = $order->lines->every(fn ($line): bool => (float) $line->received_qty >= (float) $line->ordered_qty);
            $order->update(['status' => $isComplete || ($receipt->is_final_delivery && !$hasShortage) ? 'received' : 'partially_received', 'receiving_closed' => $isComplete || ($receipt->is_final_delivery && !$hasShortage)]);
            app(AuditService::class)->record('goods_receipt.approved', $receipt, ['status' => 'pending'], ['status' => 'approved']);
            return $receipt->fresh();
        });
        return response()->json(['data' => $receipt->load('purchaseOrder', 'lines.product'), 'status' => $receipt->status]);
    }

    public function rejectReceipt(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:3000']]);
        $companyId = $request->user()?->company_id;
        $actorId = $request->user()?->id ?? auth()->id();
        $receipt = DB::transaction(function () use ($data, $id, $companyId, $actorId): GoodsReceipt {
            $receipt = GoodsReceipt::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'pending') throw new \RuntimeException('Only pending goods receipts can be rejected.');
            app(ApprovalGuard::class)->assertDifferent($receipt);
            $before = $receipt->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $receipt->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $actorId, 'rejected_at' => now()]);
            app(AuditService::class)->record('goods_receipt.rejected', $receipt, $before, $receipt->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $receipt->fresh();
        });
        return response()->json(['data' => $receipt->load('purchaseOrder', 'lines.product'), 'status' => $receipt->status]);
    }

    public function resolveReceiptDiscrepancy(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['resolution' => ['required', 'in:accept_shortage'], 'resolution_reason' => ['required', 'string', 'max:3000']]);
        $companyId = $request->user()?->company_id;
        $actorId = $request->user()?->id ?? auth()->id();
        $receipt = DB::transaction(function () use ($data, $id, $companyId, $actorId): GoodsReceipt {
            $receipt = GoodsReceipt::where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'approved' || $receipt->discrepancy_status !== 'open') throw new \RuntimeException('Only approved receipts with an open discrepancy can be resolved.');
            app(ApprovalGuard::class)->assertDifferent($receipt);
            $before = $receipt->only(['discrepancy_status', 'discrepancy_resolution', 'discrepancy_resolved_by', 'discrepancy_resolved_at']);
            $receipt->update(['discrepancy_status' => 'accepted', 'discrepancy_resolution' => $data['resolution'].': '.$data['resolution_reason'], 'discrepancy_resolved_by' => $actorId, 'discrepancy_resolved_at' => now()]);
            PurchaseOrder::whereKey($receipt->purchase_order_id)->lockForUpdate()->update(['status' => 'received', 'receiving_closed' => true]);
            app(AuditService::class)->record('goods_receipt.discrepancy_resolved', $receipt, $before, $receipt->fresh()->only(['discrepancy_status', 'discrepancy_resolution', 'discrepancy_resolved_by', 'discrepancy_resolved_at']));
            return $receipt->fresh();
        });
        return response()->json(['data' => $receipt->load('purchaseOrder', 'lines.product'), 'status' => $receipt->discrepancy_status]);
    }

    public function cancelOrder(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        $order = DB::transaction(function () use ($id, $data): PurchaseOrder {
            $order = PurchaseOrder::with('lines')->lockForUpdate()->findOrFail($id);
            if (!in_array($order->status, ['submitted', 'approved'], true)) throw new \RuntimeException('Only submitted or approved purchase orders can be cancelled.');
            if ($order->receipts()->where('status', 'approved')->exists() || (float) $order->lines->sum('received_qty') > 0) throw new \RuntimeException('A purchase order with posted receipts cannot be cancelled.');
            $before = $order->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']);
            $order->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
            app(AuditService::class)->record('purchase_order.cancelled', $order, $before, $order->fresh()->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']));
            return $order->fresh();
        });
        return response()->json(['data' => $order->load('supplier', 'lines.product'), 'status' => $order->status]);
    }

    public function rejectInvoice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $invoice = DB::transaction(function () use ($data, $id): PurchaseInvoice {
            $invoice = PurchaseInvoice::lockForUpdate()->findOrFail($id);
            if ($invoice->status !== 'pending') throw new \RuntimeException('Only pending purchase invoices can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($invoice);
            $before = $invoice->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $invoice->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('purchase_invoice.rejected', $invoice, $before, $invoice->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $invoice->fresh();
        });
        return response()->json(['data' => $invoice->load('supplier', 'purchaseOrder', 'lines.product'), 'status' => $invoice->status]);
    }

    public function landedCosts(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,reversed'], 'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for landed-cost synchronization.');
        $costs = LandedCost::with(['goodsReceipt.purchaseOrder.supplier', 'goodsReceipt.lines.product', 'allocations', 'layerAdjustments'])
            ->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($costs, $request, 'purchasing.landed-costs', (int) ($data['per_page'] ?? 50));
    }

    public function createLandedCost(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        abort_unless($companyId, 403, 'A company is required for landed cost.');
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'],
            'cost_no' => ['nullable', 'string', 'max:100'],
            'goods_receipt_id' => ['required', 'integer', Rule::exists('goods_receipts', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'cost_type' => ['required', 'string', 'max:50'], 'amount' => ['required', 'numeric', 'gt:0'],
            'allocation_method' => ['required', 'in:by_value,by_quantity'], 'description' => ['nullable', 'string', 'max:2000'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = LandedCost::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('goodsReceipt', 'allocations', 'layerAdjustments'), 'status' => 'duplicate_ignored']);
        }
        $receipt = GoodsReceipt::where('company_id', $companyId)->with('lines')->findOrFail($data['goods_receipt_id']);
        if ($receipt->status !== 'approved') throw new \RuntimeException('Landed costs require an approved goods receipt.');
        $cost = LandedCost::create($data + [
            'company_id' => $companyId, 'cost_no' => $data['cost_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('landed_cost', 'LC-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
            'status' => 'pending', 'created_by' => $request->user()?->id,
        ]);
        app(AuditService::class)->record('landed_cost.created', $cost, null, $cost->toArray());
        return response()->json(['data' => $cost->load('goodsReceipt', 'allocations', 'layerAdjustments'), 'status' => 'pending_approval'], 201);
    }

    public function approveLandedCost(Request $request, int $id): JsonResponse
    {
        try {
            app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(LandedCost::class, $id);
            $cost = LandedCost::where('company_id', $request->user()?->company_id)->findOrFail($id);
            if ($cost->status !== 'pending') throw new \RuntimeException('Only pending landed costs can be approved.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($cost);
            app(LandedCostService::class)->approve($cost);
            app(AuditService::class)->record('landed_cost.approved', $cost, ['status' => 'pending'], ['status' => 'approved']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $cost->fresh()->load('goodsReceipt', 'allocations', 'layerAdjustments'), 'status' => 'approved']);
    }

    public function rejectLandedCost(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $cost = DB::transaction(function () use ($request, $id, $data): LandedCost {
            $cost = LandedCost::where('company_id', $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($cost->status !== 'pending') throw new \RuntimeException('Only pending landed costs can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($cost);
            $before = $cost->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $cost->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $request->user()?->id, 'rejected_at' => now()]);
            app(AuditService::class)->record('landed_cost.rejected', $cost, $before, $cost->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $cost->fresh();
        });
        return response()->json(['data' => $cost->load('goodsReceipt', 'allocations', 'layerAdjustments'), 'status' => 'rejected']);
    }

    public function reverseLandedCost(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reversal_reason' => ['required', 'string', 'max:2000']]);
        try {
            $cost = LandedCost::where('company_id', $request->user()?->company_id)->findOrFail($id);
            app(ApprovalGuard::class)->assertDifferent($cost);
            app(LandedCostService::class)->reverse($cost, $data['reversal_reason']);
            app(AuditService::class)->record('landed_cost.reversed', $cost, ['status' => 'approved'], $cost->fresh()->only(['status', 'reversal_reason', 'reversed_by', 'reversed_at']));
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $cost->fresh()->load('goodsReceipt', 'allocations', 'layerAdjustments'), 'status' => 'reversed']);
    }
}
