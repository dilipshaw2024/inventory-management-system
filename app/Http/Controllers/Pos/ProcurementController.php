<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\GoodsReceiptRequest;
use App\Http\Requests\Pos\PurchaseOrderRequest;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\InventoryBatch;
use App\Models\InventorySerial;
use App\Models\Unit;
use App\Models\InventoryLocation;
use App\Services\AuditService;
use App\Services\InventoryLedgerService;
use App\Services\UomConversionService;
use App\Services\NumberingSequenceService;
use App\Services\SupplierProductPriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ProcurementController extends Controller
{
    public function orders()
    {
        $orders = PurchaseOrder::with('supplier')->latest()->paginate(30);
        return view('backend.purchase.order_all', compact('orders'));
    }

    public function createOrder(Request $request)
    {
        $suppliers = Supplier::where('status', 1)->orderBy('name')->get();
        $products = Product::where('status', 1)->orderBy('name')->get();
        $units = Unit::where('status', 1)->orderBy('name')->get();
        $selectedProductId = $request->integer('product_id') ?: null;
        $selectedSupplierId = $request->integer('supplier_id') ?: null;
        $suggestedQuantity = $request->input('quantity');
        $suggestedPrice = $request->input('price');
        return view('backend.purchase.order_add', compact('suppliers', 'products', 'units', 'selectedProductId', 'selectedSupplierId', 'suggestedQuantity', 'suggestedPrice'));
    }

    public function storeOrder(PurchaseOrderRequest $request)
    {
        $order = DB::transaction(function () use ($request): PurchaseOrder {
            $order = PurchaseOrder::create($request->only(['supplier_id', 'date', 'expected_date', 'description']) + ['company_id' => auth()->user()?->company_id, 'currency_code' => strtoupper($request->currency_code ?: (auth()->user()?->company?->base_currency ?? 'USD')), 'exchange_rate' => $request->exchange_rate ?: 1, 'po_no' => $request->po_no ?: app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'created_by' => auth()->id(), 'status' => 'submitted']);
            foreach ($request->product_id as $index => $productId) {
                $product = Product::findOrFail($productId);
                $uomId = $request->uom_id[$index] ?? null;
                $enteredQty = (float) $request->ordered_qty[$index];
                $stockQty = app(UomConversionService::class)->toStock($product, $enteredQty, $uomId ? (int) $uomId : null, 'purchase');
                $unitPrice = $uomId ? (float) $request->unit_price[$index] / max($stockQty / $enteredQty, 0.000001) : (float) $request->unit_price[$index];
                $priceAgreement = app(SupplierProductPriceService::class)->bestFor($order->supplier, $product, $stockQty, now()->toDateString(), $order->currency_code);
                if ($priceAgreement && (float) $request->unit_price[$index] <= 0) $unitPrice = (float) $priceAgreement->unit_price;
                PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $productId, 'uom_id' => $uomId, 'uom_quantity' => $enteredQty, 'ordered_qty' => $stockQty, 'unit_price' => $unitPrice]);
            }
            app(AuditService::class)->record('purchase_order.created', $order, null, $order->toArray());
            return $order;
        });
        return redirect()->route('procurement.orders')->with(['message' => 'Purchase order submitted.', 'alert-type' => 'success']);
    }

    public function approveOrder(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(PurchaseOrder::class, $id);
        $order = PurchaseOrder::findOrFail($id);
        if ($order->status !== 'submitted') return back()->with(['message' => 'Only submitted orders can be approved.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($order); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $order->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        app(AuditService::class)->record('purchase_order.approved', $order, ['status' => 'submitted'], ['status' => 'approved']);
        return back()->with(['message' => 'Purchase order approved.', 'alert-type' => 'success']);
    }

    public function cancelOrder(Request $request, int $id)
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'max:2000']]);
        $order = PurchaseOrder::withCount(['receipts as posted_receipts_count' => fn ($query) => $query->where('status', 'approved')])->findOrFail($id);
        if (!in_array($order->status, ['submitted', 'approved'], true)) return back()->with(['message' => 'Only submitted or approved purchase orders can be cancelled.', 'alert-type' => 'error']);
        if ($order->posted_receipts_count > 0 || (float) $order->lines()->sum('received_qty') > 0) return back()->with(['message' => 'A purchase order with posted receipts cannot be cancelled.', 'alert-type' => 'error']);
        $before = $order->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']);
        $order->update(['status' => 'cancelled', 'cancellation_reason' => $data['cancellation_reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
        app(AuditService::class)->record('purchase_order.cancelled', $order, $before, $order->fresh()->only(['status', 'cancellation_reason', 'cancelled_by', 'cancelled_at']));
        return back()->with(['message' => 'Purchase order cancelled.', 'alert-type' => 'success']);
    }

    public function rejectOrder(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $order = PurchaseOrder::findOrFail($id);
        if ($order->status !== 'submitted') return back()->with(['message' => 'Only submitted purchase orders can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($order); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $order->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $order->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('purchase_order.rejected', $order, $before, $order->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Purchase order rejected.', 'alert-type' => 'success']);
    }

    public function receipts()
    {
        $receipts = GoodsReceipt::with('purchaseOrder.supplier')->latest()->paginate(30);
        return view('backend.purchase.receipt_all', compact('receipts'));
    }

    public function createReceipt()
    {
        $orders = PurchaseOrder::whereIn('status', ['approved', 'partially_received'])->with(['supplier', 'lines.product'])->latest()->get();
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get();
        $units = Unit::where('status', 1)->orderBy('name')->get(['id', 'name']);
        return view('backend.purchase.receipt_add', compact('orders', 'locations', 'units'));
    }

    public function storeReceipt(GoodsReceiptRequest $request)
    {
            $receipt = DB::transaction(function () use ($request): GoodsReceipt {
            $order = PurchaseOrder::whereIn('status', ['approved', 'partially_received'])->findOrFail($request->purchase_order_id);
            $receipt = GoodsReceipt::create(['company_id' => $order->company_id ?: auth()->user()?->company_id, 'grn_no' => $request->grn_no ?: app(NumberingSequenceService::class)->nextOrFallback('goods_receipt', 'GRN-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'purchase_order_id' => $order->id, 'location_id' => $request->location_id, 'date' => $request->date, 'description' => $request->description, 'inspection_status' => $request->boolean('inspection_required') ? 'pending' : 'not_required', 'created_by' => auth()->id()]);
            foreach ($request->line_id as $index => $lineId) {
                $line = $order->lines()->whereKey($lineId)->firstOrFail();
                $uomId = $request->uom_id[$index] ?? null;
                $enteredQty = (float) $request->received_qty[$index];
                $receivedQty = app(UomConversionService::class)->toStock($line->product, $enteredQty, $uomId ? (int) $uomId : null, 'purchase');
                $conversion = $enteredQty > 0 ? $receivedQty / $enteredQty : 1;
                $remaining = (float) $line->ordered_qty - (float) $line->received_qty;
                if ($receivedQty > $remaining) throw new \RuntimeException('Receipt exceeds remaining quantity for '.$line->product->name.'.');
                GoodsReceiptLine::create(['goods_receipt_id' => $receipt->id, 'purchase_order_line_id' => $line->id, 'product_id' => $line->product_id, 'uom_id' => $uomId, 'uom_quantity' => $enteredQty, 'received_qty' => $receivedQty, 'unit_cost' => (float) $request->unit_cost[$index] / max($conversion, 0.000001), 'batch_no' => $request->batch_no[$index] ?? null, 'serial_numbers' => $request->serial_numbers[$index] ?? null, 'manufacturing_date' => $request->manufacturing_date[$index] ?? null, 'expiry_date' => $request->expiry_date[$index] ?? null, 'best_before_date' => $request->best_before_date[$index] ?? null, 'warranty_until' => $request->warranty_until[$index] ?? null]);
            }
            app(AuditService::class)->record('goods_receipt.created', $receipt, null, $receipt->toArray());
            return $receipt;
        });
        return redirect()->route('procurement.receipts')->with(['message' => 'Goods receipt submitted for approval.', 'alert-type' => 'success']);
    }

    public function approveReceipt(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(GoodsReceipt::class, $id);
        try {
            DB::transaction(function () use ($id): void {
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
                        $serials = $line->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\\r\\n]+/', $line->serial_numbers)))) : [];
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
                $receipt->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                $order = PurchaseOrder::with('lines')->findOrFail($receipt->purchase_order_id);
                $order->update(['status' => $order->lines->every(fn ($line) => (float) $line->received_qty >= (float) $line->ordered_qty) ? 'received' : 'partially_received']);
                app(AuditService::class)->record('goods_receipt.approved', $receipt, ['status' => 'pending'], ['status' => 'approved']);
            });
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        return redirect()->route('procurement.receipts')->with(['message' => 'Goods receipt approved and stock posted.', 'alert-type' => 'success']);
    }

    public function inspectReceipt(Request $request, int $id)
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        try {
            DB::transaction(function () use ($data, $id): void {
                $receipt = GoodsReceipt::lockForUpdate()->findOrFail($id);
                if ($receipt->status !== 'pending' || $receipt->inspection_status !== 'pending') throw new \RuntimeException('Only pending inspection receipts can be inspected.');
                $before = $receipt->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
                $receipt->update(['inspection_status' => $data['inspection_status'], 'inspection_notes' => $data['inspection_notes'], 'inspected_by' => auth()->id(), 'inspected_at' => now()]);
                app(AuditService::class)->record('goods_receipt.inspected', $receipt, $before, $receipt->fresh()->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']));
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => $data['inspection_status'] === 'passed' ? 'Goods receipt inspection passed.' : 'Goods receipt inspection failed; stock remains unposted.', 'alert-type' => $data['inspection_status'] === 'passed' ? 'success' : 'warning']);
    }

    public function rejectReceipt(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $receipt = GoodsReceipt::findOrFail($id);
        if ($receipt->status !== 'pending') return back()->with(['message' => 'Only pending goods receipts can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($receipt); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $receipt->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $receipt->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('goods_receipt.rejected', $receipt, $before, $receipt->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Goods receipt rejected.', 'alert-type' => 'success']);
    }
}
