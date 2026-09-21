<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Delivery;
use App\Models\Customer;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnLine;
use App\Models\Invoice;
use App\Models\InventorySerial;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Branch;
use App\Models\InventoryBatch;
use App\Services\AuditService;
use App\Services\AutomaticAccountingService;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryLedgerService;
use App\Services\NumberingSequenceService;
use App\Services\SerialLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReturnIntegrationController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'return_type' => ['required', 'in:sales,purchase'],
            'external_reference' => ['nullable', 'string', 'max:150'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)],
            'source_invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'source_goods_receipt_id' => ['nullable', 'integer', Rule::exists('goods_receipts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'source_delivery_id' => ['nullable', 'integer', Rule::exists('deliveries', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'date' => ['required', 'date'], 'reason_code' => ['required', 'string', 'max:100'], 'inspection_required' => ['nullable', 'boolean'], 'description' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_id' => ['nullable', 'integer'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'], 'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.serial_numbers' => ['nullable', 'string', 'max:5000'],
            'lines.*.component_serial_numbers' => ['nullable', 'array'], 'lines.*.component_serial_numbers.*' => ['array'], 'lines.*.component_serial_numbers.*.*' => ['string', 'max:300'],
        ]);
        $ability = $data['return_type'] === 'sales' ? 'sales:write' : 'purchasing:write';
        if (!$request->user()?->tokenCan($ability) && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot create the selected return type.');
        if ($data['return_type'] === 'sales' && empty($data['customer_id'])) abort(422, 'A customer is required for a sales return.');
        if ($data['return_type'] === 'purchase' && empty($data['supplier_id'])) abort(422, 'A supplier is required for a purchase return.');
        if ($data['return_type'] === 'sales' && !empty($data['source_goods_receipt_id'])) abort(422, 'Sales returns must reference a sales invoice, not a goods receipt.');
        if ($data['return_type'] === 'purchase' && !empty($data['source_invoice_id'])) abort(422, 'Purchase returns must reference a goods receipt, not a sales invoice.');
        if ($data['return_type'] === 'purchase' && !empty($data['source_delivery_id'])) abort(422, 'Purchase returns cannot reference a sales delivery.');
        if ($data['return_type'] === 'sales' && !empty($data['source_delivery_id']) && !empty($data['source_invoice_id'])) abort(422, 'Sales returns cannot reference both a delivery and an invoice.');
        if (!empty($data['external_reference'])) {
            $existing = InventoryReturn::where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('customer', 'supplier', 'lines.product', 'lines.batch'), 'status' => 'duplicate_ignored']);
        }
        $party = $data['return_type'] === 'sales' ? Customer::findOrFail($data['customer_id']) : Supplier::findOrFail($data['supplier_id']);
        $return = DB::transaction(function () use ($data, $companyId, $party, $request): InventoryReturn {
            $return = InventoryReturn::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'return_no' => app(NumberingSequenceService::class)->nextOrFallback('inventory_return', 'RET-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'return_type' => $data['return_type'], 'customer_id' => $data['customer_id'] ?? null, 'supplier_id' => $data['supplier_id'] ?? null,
                'source_invoice_id' => $data['source_invoice_id'] ?? null, 'source_goods_receipt_id' => $data['source_goods_receipt_id'] ?? null, 'source_delivery_id' => $data['source_delivery_id'] ?? null, 'location_id' => $data['location_id'] ?? null,
                'date' => $data['date'], 'reason_code' => $data['reason_code'], 'inspection_required' => (bool) ($data['inspection_required'] ?? false), 'inspection_status' => !empty($data['inspection_required']) ? 'pending' : 'not_required', 'description' => $data['description'] ?? null,
                'tax_exempt' => (bool) $party?->tax_exempt, 'tax_exemption_number' => $party?->tax_exemption_number, 'tax_jurisdiction' => $party?->tax_jurisdiction, 'created_by' => $request->user()?->id,
            ]);
            foreach ($data['lines'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $expanded = $data['return_type'] === 'sales' && ($product->product_type ?: 'stock') === 'bundle'
                    ? app(\App\Services\BundleFulfillmentService::class)->expandReturnLine($product, (float) $line['quantity'], (float) ($line['unit_cost'] ?? 0), isset($line['unit_price']) ? (float) $line['unit_price'] : null, isset($line['tax_rate']) ? (float) $line['tax_rate'] : null, $line['component_serial_numbers'] ?? [])
                    : [['product_id' => $product->id, 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'] ?? 0, 'unit_price' => $line['unit_price'] ?? null, 'tax_rate' => $line['tax_rate'] ?? null, 'serial_numbers' => $line['serial_numbers'] ?? null]];
                foreach ($expanded as $expandedLine) InventoryReturnLine::create(['return_id' => $return->id, 'product_id' => $expandedLine['product_id'], 'batch_id' => count($expanded) === 1 ? ($line['batch_id'] ?? null) : null, 'quantity' => $expandedLine['quantity'], 'unit_cost' => $expandedLine['unit_cost'], 'unit_price' => $expandedLine['unit_price'], 'tax_rate' => $expandedLine['tax_rate'], 'serial_numbers' => $expandedLine['serial_numbers'] ?? null]);
            }
            app(AuditService::class)->record('inventory_return.created', $return, null, $return->toArray());
            return $return;
        });
        return response()->json(['data' => $return->load('customer', 'supplier', 'lines.product', 'lines.batch'), 'status' => 'pending_approval'], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryReturn::class, $id);
        $inspection = InventoryReturn::findOrFail($id);
        if ($inspection->inspection_required && $inspection->inspection_status !== 'passed') return response()->json(['message' => 'This return must pass inspection before approval.'], 422);
        $return = DB::transaction(function () use ($request, $id): InventoryReturn {
            $return = InventoryReturn::with('lines.product')->lockForUpdate()->findOrFail($id);
            $ability = $return->return_type === 'sales' ? 'sales:write' : 'purchasing:write';
            if (!$request->user()?->tokenCan($ability) && !$request->user()?->tokenCan('integration:write')) abort(403, 'This token cannot approve the selected return type.');
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
            if ($return->return_type === 'sales' && $return->source_delivery_id) {
                $source = Delivery::with(['salesOrder', 'lines.product'])->where('status', 'approved')->lockForUpdate()->findOrFail($return->source_delivery_id);
                if ($return->customer_id && (int) $return->customer_id !== (int) $source->salesOrder->customer_id) throw new \RuntimeException('Return customer does not match the source delivery customer.');
                foreach ($return->lines as $line) {
                    $delivered = (float) $source->lines->where('product_id', $line->product_id)->when($line->batch_id, fn ($rows) => $rows->where('batch_id', $line->batch_id))->sum('delivered_qty');
                    if ($delivered <= 0) foreach ($source->lines as $sourceLine) {
                        if (($sourceLine->product?->product_type ?: 'stock') === 'bundle') $delivered += (float) (app(\App\Services\BundleFulfillmentService::class)->requirements($sourceLine->product, (float) $sourceLine->delivered_qty)[$line->product_id] ?? 0);
                    }
                    $alreadyReturned = (float) InventoryReturnLine::whereHas('inventoryReturn', fn ($query) => $query->where('source_delivery_id', $source->id)->where('status', 'approved')->where('inventory_returns.id', '<>', $return->id))->where('product_id', $line->product_id)->when($line->batch_id, fn ($query) => $query->where('batch_id', $line->batch_id))->sum('quantity');
                    if ($alreadyReturned + (float) $line->quantity > $delivered + 0.000001) throw new \RuntimeException('Return quantity exceeds the source delivery quantity for '.$line->product->name.'.');
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
                $product = Product::lockForUpdate()->findOrFail($line->product_id); $salesReturn = $return->return_type === 'sales'; $batch = $line->batch_id ? InventoryBatch::whereKey($line->batch_id)->where('product_id', $product->id)->lockForUpdate()->first() : null; if ($line->batch_id && !$batch) throw new \RuntimeException('Selected batch does not belong to '.$product->name.'.'); $serials = array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', (string) $line->serial_numbers)))); $changedSerials = collect();
                if ($product->tracking_type === 'serial') {
                    if (count($serials) !== (int) round((float) $line->quantity)) throw new \RuntimeException('Serial count must equal returned quantity for '.$product->name.'.');
                    $changedSerials = $salesReturn ? app(SerialLifecycleService::class)->returnToStock($product, $serials, $return->location_id, $batch?->id) : app(SerialLifecycleService::class)->issueSpecific($product, $serials, $return->location_id, $batch?->id);
                }
                if (!$salesReturn && app(InventoryAvailabilityService::class)->available($product, true, $return->location_id, $return->company_id) < (float) $line->quantity) throw new \RuntimeException('Insufficient available stock for supplier return: '.$product->name.'.');
                $product->quantity = (float) $product->quantity + ($salesReturn ? (float) $line->quantity : -(float) $line->quantity); $product->save();
                $movementType = $salesReturn ? 'return_in' : 'return_out';
                if ($changedSerials->isNotEmpty()) foreach ($changedSerials as $serial) app(InventoryLedgerService::class)->post($product->id, $movementType, 1, (float) $line->unit_cost, $return->location_id, $return, $return->reason_code, null, $batch?->id ?: $serial->batch_id, $serial->id);
                else app(InventoryLedgerService::class)->post($product->id, $movementType, (float) $line->quantity, (float) $line->unit_cost, $return->location_id, $return, $return->reason_code, null, $batch?->id);
            }
            $return->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now()]);
            app(AutomaticAccountingService::class)->postSalesReturn($return->load('lines.product'));
            app(AuditService::class)->record('inventory_return.approved', $return, ['status' => 'pending'], ['status' => 'approved']);
            return $return->fresh();
        });
        return response()->json(['data' => $return->load('customer', 'supplier', 'lines.product', 'lines.batch'), 'status' => $return->status]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $return = DB::transaction(function () use ($data, $id): InventoryReturn {
            $return = InventoryReturn::lockForUpdate()->findOrFail($id);
            if ($return->status !== 'pending') throw new \RuntimeException('Only pending returns can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($return);
            $before = $return->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $return->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('inventory_return.rejected', $return, $before, $return->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return $return->fresh();
        });
        return response()->json(['data' => $return->load('customer', 'supplier', 'lines.product'), 'status' => $return->status]);
    }

    public function inspect(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        try {
            $return = DB::transaction(function () use ($data, $id, $request): InventoryReturn {
                $return = InventoryReturn::lockForUpdate()->findOrFail($id);
                if ($return->status !== 'pending' || !$return->inspection_required || $return->inspection_status !== 'pending') throw new \RuntimeException('Only pending returns awaiting inspection can be inspected.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($return);
                $before = $return->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
                $return->update(['inspection_status' => $data['inspection_status'], 'inspection_notes' => $data['inspection_notes'], 'inspected_by' => $request->user()?->id, 'inspected_at' => now()]);
                app(AuditService::class)->record('inventory_return.inspected', $return, $before, $return->fresh()->only(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']));
                return $return->fresh();
            });
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
        return response()->json(['data' => $return, 'status' => $return->inspection_status]);
    }
}
