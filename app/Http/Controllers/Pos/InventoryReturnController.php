<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\InventoryReturnRequest;
use App\Models\Customer;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\GoodsReceipt;
use App\Models\InventoryLocation;
use App\Models\InventoryBatch;
use App\Services\AuditService;
use App\Services\InventoryLedgerService;
use App\Services\NumberingSequenceService;
use App\Services\SerialLifecycleService;
use Illuminate\Support\Facades\DB;

class InventoryReturnController extends Controller
{
    public function index() { $returns = InventoryReturn::with(['customer', 'supplier', 'location', 'sourceInvoice', 'creator', 'refunds', 'lines.product', 'lines.batch'])->latest()->paginate(30); return view('backend.stock.return_all', compact('returns')); }
    public function create() { $products = Product::where('status', 1)->orderBy('name')->get(); $customers = Customer::where('status', 1)->orderBy('name')->get(); $suppliers = Supplier::where('status', 1)->orderBy('name')->get(); $invoices = Invoice::where('status', 1)->orderByDesc('date')->get(['id', 'invoice_no', 'date']); $receipts = GoodsReceipt::where('status', 'approved')->with('purchaseOrder.supplier')->latest('date')->get(); $locations = InventoryLocation::where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))->orderBy('code')->get(); return view('backend.stock.return_add', compact('products', 'customers', 'suppliers', 'invoices', 'receipts', 'locations')); }
    public function store(InventoryReturnRequest $request)
    {
        try {
            $return = DB::transaction(function () use ($request): InventoryReturn {
                if ($request->return_type === 'sales' && !$request->customer_id) throw new \RuntimeException('A customer is required for a sales return.');
                if ($request->return_type === 'purchase' && !$request->supplier_id) throw new \RuntimeException('A supplier is required for a purchase return.');
                $party = $request->return_type === 'sales' ? Customer::find($request->customer_id) : Supplier::find($request->supplier_id);
                $inspectionRequired = $request->boolean('inspection_required');
                $return = InventoryReturn::create($request->only(['return_type', 'customer_id', 'source_invoice_id', 'source_goods_receipt_id', 'supplier_id', 'location_id', 'date', 'reason_code', 'description']) + ['inspection_required' => $inspectionRequired, 'inspection_status' => $inspectionRequired ? 'pending' : 'not_required', 'tax_exempt' => (bool) ($party?->tax_exempt), 'tax_exemption_number' => $party?->tax_exemption_number, 'tax_jurisdiction' => $party?->tax_jurisdiction, 'return_no' => $request->return_no ?: app(NumberingSequenceService::class)->nextOrFallback('inventory_return', 'RET-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'created_by' => auth()->id()]);
                foreach ($request->product_id as $index => $productId) {
                    $product = Product::findOrFail($productId);
                    $lines = $request->return_type === 'sales' && ($product->product_type ?: 'stock') === 'bundle'
                        ? app(\App\Services\BundleFulfillmentService::class)->expandReturnLine($product, (float) $request->quantity[$index], (float) ($request->unit_cost[$index] ?? 0), $request->unit_price[$index] ?? null, $request->tax_rate[$index] ?? null, $request->component_serial_numbers[$index] ?? [])
                        : [['product_id' => $product->id, 'quantity' => $request->quantity[$index], 'unit_cost' => $request->unit_cost[$index] ?? 0, 'unit_price' => $request->unit_price[$index] ?? null, 'tax_rate' => $request->tax_rate[$index] ?? null, 'serial_numbers' => $request->serial_numbers[$index] ?? null]];
                    foreach ($lines as $line) InventoryReturnLine::create(['return_id' => $return->id, 'product_id' => $line['product_id'], 'batch_id' => count($lines) === 1 ? ($request->batch_id[$index] ?? null) : null, 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'], 'unit_price' => $line['unit_price'], 'tax_rate' => $line['tax_rate'], 'serial_numbers' => $line['serial_numbers'] ?? null]);
                }
                app(AuditService::class)->record('inventory_return.created', $return, null, $return->toArray());
                return $return;
            });
        } catch (\RuntimeException $exception) { return back()->withInput()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return redirect()->route('inventory.returns.index')->with(['message' => 'Return submitted for approval.', 'alert-type' => 'success']);
    }
    public function approve(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryReturn::class, $id);
        try {
            DB::transaction(function () use ($id): void {
                $return = InventoryReturn::with('lines')->lockForUpdate()->findOrFail($id);
                if ($return->status !== 'pending') throw new \RuntimeException('This return has already been processed.');
                if ($return->inspection_required && $return->inspection_status !== 'passed') throw new \RuntimeException('This return must pass inspection before approval.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($return);
                if ($return->return_type === 'sales' && $return->source_invoice_id) {
                    $source = Invoice::with('invoice_details.product')->lockForUpdate()->findOrFail($return->source_invoice_id);
                    if ($return->customer_id && Payment::where('invoice_id', $source->id)->value('customer_id') && (int) $return->customer_id !== (int) Payment::where('invoice_id', $source->id)->value('customer_id')) throw new \RuntimeException('Return customer does not match the source invoice.');
                    foreach ($return->lines as $line) {
                        $invoiced = (float) $source->invoice_details->where('product_id', $line->product_id)->when($line->batch_id, fn ($rows) => $rows->where('batch_id', $line->batch_id))->sum('selling_qty');
                        if ($invoiced <= 0) foreach ($source->invoice_details as $sourceLine) {
                            if (($sourceLine->product?->product_type ?: 'stock') === 'bundle') $invoiced += (float) (app(\App\Services\BundleFulfillmentService::class)->requirements($sourceLine->product, (float) $sourceLine->selling_qty)[$line->product_id] ?? 0);
                        }
                        $alreadyReturned = (float) InventoryReturnLine::whereHas('inventoryReturn', fn ($query) => $query->where('source_invoice_id', $source->id)->where('status', 'approved')->where('inventory_returns.id', '<>', $return->id))->where('product_id', $line->product_id)->when($line->batch_id, fn ($query) => $query->where('batch_id', $line->batch_id))->sum('quantity');
                        if ($alreadyReturned + (float) $line->quantity > $invoiced + 0.000001) throw new \RuntimeException('Return quantity exceeds the source invoice quantity for '.$line->product->name.'.');
                    }
                }
                if ($return->return_type === 'purchase' && $return->source_goods_receipt_id) {
                    $source = GoodsReceipt::with(['purchaseOrder', 'lines'])->where('status', 'approved')->lockForUpdate()->findOrFail($return->source_goods_receipt_id);
                    if ($return->supplier_id && (int) $return->supplier_id !== (int) $source->purchaseOrder->supplier_id) throw new \RuntimeException('Return supplier does not match the source goods receipt.');
                    foreach ($return->lines as $line) {
                        $received = (float) $source->lines->where('product_id', $line->product_id)->when($line->batch_id, fn ($rows) => $rows->where('batch_id', $line->batch_id))->sum('received_qty');
                        $alreadyReturned = (float) InventoryReturnLine::whereHas('inventoryReturn', fn ($query) => $query->where('source_goods_receipt_id', $source->id)->where('status', 'approved')->where('inventory_returns.id', '<>', $return->id))->where('product_id', $line->product_id)->when($line->batch_id, fn ($query) => $query->where('batch_id', $line->batch_id))->sum('quantity');
                        if ($alreadyReturned + (float) $line->quantity > $received + 0.000001) throw new \RuntimeException('Return quantity exceeds the source goods receipt quantity for '.$line->product->name.'.');
                    }
                }
                foreach ($return->lines as $line) {
                    $product = Product::lockForUpdate()->findOrFail($line->product_id);
                    $salesReturn = $return->return_type === 'sales';
                    $batch = null;
                    if ($line->batch_id) {
                        $batch = InventoryBatch::whereKey($line->batch_id)->where('product_id', $product->id)->lockForUpdate()->first();
                        if (!$batch) throw new \RuntimeException('Selected batch does not belong to '.$product->name.'.');
                    }
                    $returnedSerials = collect();
                    if ($salesReturn && $product->tracking_type === 'serial') {
                        $serials = array_filter(array_map('trim', preg_split('/[,\\r\\n]+/', (string) $line->serial_numbers)));
                        if (count($serials) !== (int) $line->quantity) throw new \RuntimeException('Serial count must equal returned quantity for '.$product->name.'.');
                            $returnedSerials = app(SerialLifecycleService::class)->returnToStock($product, $serials, $return->location_id, $batch?->id);
                    } elseif (!$salesReturn && $product->tracking_type === 'serial') {
                        $serials = array_filter(array_map('trim', preg_split('/[,\\r\\n]+/', (string) $line->serial_numbers)));
                        if (count($serials) !== (int) $line->quantity) throw new \RuntimeException('Serial count must equal returned quantity for '.$product->name.'.');
                        $returnedSerials = app(SerialLifecycleService::class)->issueSpecific($product, $serials, $return->location_id, $batch?->id);
                    }
                    if (!$salesReturn && app(\App\Services\InventoryAvailabilityService::class)->available($product, true, $return->location_id, $return->company_id) < (float) $line->quantity) throw new \RuntimeException('Insufficient available stock for supplier return: '.$product->name.'.');
                    $product->quantity = (float) $product->quantity + ($salesReturn ? (float) $line->quantity : -(float) $line->quantity);
                    $product->save();
                    $movementType = $salesReturn ? 'return_in' : 'return_out';
                    if ($returnedSerials->isNotEmpty()) {
                        foreach ($returnedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, $movementType, 1, (float) $line->unit_cost, $return->location_id, $return, $return->reason_code, null, $batch?->id ?: $serial->batch_id, $serial->id);
                    } else {
                        app(InventoryLedgerService::class)->post($product->id, $movementType, (float) $line->quantity, (float) $line->unit_cost, $return->location_id, $return, $return->reason_code, null, $batch?->id);
                    }
                }
                $return->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(\App\Services\AutomaticAccountingService::class)->postSalesReturn($return->load('lines.product'));
                app(AuditService::class)->record('inventory_return.approved', $return, ['status' => 'pending'], ['status' => 'approved']);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return redirect()->route('inventory.returns.index')->with(['message' => 'Return approved and inventory posted.', 'alert-type' => 'success']);
    }

    public function reject(\Illuminate\Http\Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $return = InventoryReturn::findOrFail($id);
        if ($return->status !== 'pending') return back()->with(['message' => 'Only pending returns can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($return); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $return->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $return->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('inventory_return.rejected', $return, $before, $return->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Inventory return rejected.', 'alert-type' => 'success']);
    }

    public function inspect(Request $request, int $id)
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        try {
            $return = InventoryReturn::findOrFail($id);
            if ($return->status !== 'pending' || !$return->inspection_required || $return->inspection_status !== 'pending') throw new \RuntimeException('Only pending returns awaiting inspection can be inspected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($return);
            $before = $return->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
            $return->update(['inspection_status' => $data['inspection_status'], 'inspection_notes' => $data['inspection_notes'], 'inspected_by' => auth()->id(), 'inspected_at' => now()]);
            app(AuditService::class)->record('inventory_return.inspected', $return, $before, $return->fresh()->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']));
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Return inspection recorded.', 'alert-type' => 'success']);
    }
}
