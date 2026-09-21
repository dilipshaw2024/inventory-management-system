<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Product;
use App\Models\Category;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\InventoryMovement;
use App\Models\InventoryCostLayer;
use App\Models\InventoryLocation;
use App\Models\InventoryBatch;
use Auth;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\StockReservation;
use App\Models\InventoryStatusBalance;
 
class StockController extends Controller
{
    public function StockReport(Request $request){
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', $this->owned('products')],
            'category_id' => ['nullable', 'integer', $this->owned('categories')],
            'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany(auth()->user()?->company_id)],
        ]);
        $locationId = !empty($validated['location_id']) ? (int) $validated['location_id'] : null;
        if ($locationId !== null) InventoryLocation::whereKey($locationId)->firstOrFail();
        $allData = Product::with(['supplier', 'unit', 'category'])
            ->when($validated['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($validated['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->orderBy('id','desc')->get();
        $ids = $allData->pluck('id');
        $reserved = StockReservation::whereIn('product_id', $ids)->where('status', 'active')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->when($locationId !== null, fn ($query) => $query->where(function ($nested) use ($locationId): void { $nested->whereNull('location_id')->orWhere('location_id', $locationId); }))
            ->selectRaw('product_id, COALESCE(SUM(quantity - released_quantity), 0) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $quality = InventoryStatusBalance::whereIn('product_id', $ids)->whereIn('status', ['blocked', 'quarantine', 'damaged'])
            ->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))
            ->selectRaw('product_id, COALESCE(SUM(quantity), 0) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        $available = app(\App\Services\InventoryAvailabilityService::class)->availableMany($allData, true, $locationId, auth()->user()?->company_id);
        $onHand = InventoryMovement::whereIn('product_id', $ids)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->selectRaw("product_id, COALESCE(SUM(CASE WHEN movement_type IN ('receipt','opening','transfer_in','adjustment_in','return_in','quarantine_out','release') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in') THEN -quantity ELSE 0 END), 0) AS quantity")->groupBy('product_id')->pluck('quantity', 'product_id');
        $movementTotals = InventoryMovement::whereIn('product_id', $ids)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->selectRaw("product_id, COALESCE(SUM(CASE WHEN movement_type IN ('receipt','opening','transfer_in','adjustment_in','return_in','quarantine_out','release') THEN quantity ELSE 0 END), 0) AS inbound, COALESCE(SUM(CASE WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in') THEN quantity ELSE 0 END), 0) AS outbound")->groupBy('product_id')->get()->keyBy('product_id');
        $allData->each(function (Product $product) use ($reserved, $quality, $available, $onHand, $movementTotals, $locationId): void {
            $product->setAttribute('report_on_hand_quantity', $onHand->has($product->id) ? (float) $onHand[$product->id] : (float) $product->quantity);
            $product->setAttribute('report_inbound_quantity', (float) ($movementTotals[$product->id]->inbound ?? 0));
            $product->setAttribute('report_outbound_quantity', (float) ($movementTotals[$product->id]->outbound ?? 0));
            $product->setAttribute('reserved_quantity', (float) ($reserved[$product->id] ?? 0));
            $product->setAttribute('quality_hold_quantity', (float) ($quality[$product->id] ?? 0));
            $product->setAttribute('available_quantity', (float) ($available[$product->id] ?? 0));
        });
        $products = Product::orderBy('name')->get(['id', 'name']);
        $categories = Category::orderBy('name')->get(['id', 'name']);
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get(['id', 'code']);
        $decimalPrecision = (int) app(\App\Services\ErpSettingService::class)->get('decimal_precision', 6);
        return view('backend.stock.stock_report', compact('allData', 'products', 'categories', 'locations', 'locationId', 'decimalPrecision'));

    } // End Method


    public function StockReportPdf(Request $request){
        $validated = $request->validate(['product_id' => ['nullable', 'integer', $this->owned('products')], 'category_id' => ['nullable', 'integer', $this->owned('categories')], 'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany(auth()->user()?->company_id)], 'batch_id' => ['nullable', 'integer', 'exists:inventory_batches,id'], 'as_of' => ['nullable', 'date']]);
        $locationId = !empty($validated['location_id']) ? (int) $validated['location_id'] : null;
        if ($locationId !== null) InventoryLocation::whereKey($locationId)->firstOrFail();
        $allData = Product::with(['supplier', 'unit', 'category'])->when($validated['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))->when($validated['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))->orderBy('id','desc')->get();
        $ids = $allData->pluck('id');
        $movementTotals = InventoryMovement::whereIn('product_id', $ids)->when($locationId !== null, fn ($query) => $query->where('location_id', $locationId))->selectRaw("product_id, COALESCE(SUM(CASE WHEN movement_type IN ('receipt','opening','transfer_in','adjustment_in','return_in','quarantine_out','release') THEN quantity ELSE 0 END), 0) AS inbound, COALESCE(SUM(CASE WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in') THEN quantity ELSE 0 END), 0) AS outbound, COALESCE(SUM(CASE WHEN movement_type IN ('receipt','opening','transfer_in','adjustment_in','return_in','quarantine_out','release') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in') THEN -quantity ELSE 0 END), 0) AS on_hand")->groupBy('product_id')->get()->keyBy('product_id');
        $allData->each(function (Product $product) use ($movementTotals, $locationId): void {
            $total = $movementTotals[$product->id] ?? null;
            $product->setAttribute('report_inbound_quantity', (float) ($total->inbound ?? 0));
            $product->setAttribute('report_outbound_quantity', (float) ($total->outbound ?? 0));
            $product->setAttribute('report_on_hand_quantity', $total ? (float) $total->on_hand : (float) $product->quantity);
        });
        return view('backend.pdf.stock_report_pdf',compact('allData'));

    } // End Method


    public function StockSupplierWise(){

        $supppliers = Supplier::orderBy('id','desc')->get();
        $category = Category::orderBy('id','desc')->get();
        return view('backend.stock.supplier_product_wise_report',compact('supppliers','category'));

    } // End Method


    public function SupplierWisePdf(Request $request){

        $allData = Product::where('supplier_id',$request->supplier_id)->orderBy('id','desc')->get();
        return view('backend.pdf.supplier_wise_report_pdf',compact('allData'));

    } // End Method


    public function ProductWisePdf(Request $request){

        $product = Product::where('category_id',$request->category_id)->where('id',$request->product_id)->first();
        return view('backend.pdf.product_wise_report_pdf',compact('product'));
    } // End Method

    public function MovementReport(Request $request){
        $companyId = auth()->user()?->company_id;
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', $this->owned('products')],
            'movement_type' => ['nullable', 'string', 'in:opening,receipt,issue,transfer_in,transfer_out,adjustment_in,adjustment_out,return_in,return_out,scrap,quarantine_in,quarantine_out,reservation,release'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $movements = InventoryMovement::withoutGlobalScopes()->with(['product', 'location', 'creator'])
            ->from('inventory_movements as ledger_rows')
            ->select('ledger_rows.*')
            ->selectSub($this->movementBalanceQuery($validated['from'] ?? null, false), 'opening_balance')
            ->selectSub($this->movementBalanceQuery($validated['to'] ?? now()->toDateString(), true), 'closing_balance')
            ->where(fn ($query) => $query->where('ledger_rows.company_id', $companyId)->orWhereNull('ledger_rows.company_id'))
            ->when($validated['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($validated['movement_type'] ?? null, fn ($query, $type) => $query->where('movement_type', $type))
            ->when($validated['from'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '>=', $date))
            ->when($validated['to'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '<=', $date))
            ->latest('posted_at')->latest('id')->paginate(50)->withQueryString();

        $products = Product::orderBy('name')->get(['id', 'name']);
        return view('backend.stock.movement_report', compact('movements', 'products'));
    }

    public function MovementExport(Request $request): StreamedResponse
    {
        $companyId = auth()->user()?->company_id;
        $validated = $request->validate(['product_id' => ['nullable', 'integer', $this->owned('products')], 'movement_type' => ['nullable', 'string', 'in:opening,receipt,issue,transfer_in,transfer_out,adjustment_in,adjustment_out,return_in,return_out,scrap,quarantine_in,quarantine_out,reservation,release'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $movements = InventoryMovement::withoutGlobalScopes()->with(['product', 'location', 'creator'])
            ->from('inventory_movements as ledger_rows')
            ->select('ledger_rows.*')
            ->selectSub($this->movementBalanceQuery($validated['from'] ?? null, false), 'opening_balance')
            ->selectSub($this->movementBalanceQuery($validated['to'] ?? now()->toDateString(), true), 'closing_balance')
            ->where(fn ($query) => $query->where('ledger_rows.company_id', $companyId)->orWhereNull('ledger_rows.company_id'))
            ->when($validated['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))->when($validated['movement_type'] ?? null, fn ($query, $type) => $query->where('movement_type', $type))->when($validated['from'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '>=', $date))->when($validated['to'] ?? null, fn ($query, $date) => $query->whereDate('posted_at', '<=', $date))->latest('posted_at')->latest('id')->cursor();
        return response()->streamDownload(function () use ($movements): void { $handle = fopen('php://output', 'w'); fputcsv($handle, ['Date', 'Product', 'Movement', 'Quantity', 'Unit cost', 'Opening balance', 'Closing balance', 'Reference', 'Location', 'User']); foreach ($movements as $movement) fputcsv($handle, [$movement->posted_at, $movement->product?->name, $movement->movement_type, $movement->quantity, $movement->unit_cost, $movement->opening_balance, $movement->closing_balance, $movement->reference_no, $movement->location?->code, $movement->creator?->name]); fclose($handle); }, 'stock-ledger-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function movementBalanceQuery(?string $date, bool $inclusive)
    {
        $in = "('opening','receipt','transfer_in','adjustment_in','return_in','quarantine_out','release')";
        $out = "('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in')";
        return InventoryMovement::withoutGlobalScopes()
            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type IN $in THEN quantity WHEN movement_type IN $out THEN -quantity ELSE 0 END), 0)")
            ->whereColumn('product_id', 'ledger_rows.product_id')
            ->whereRaw('(location_id = ledger_rows.location_id OR (location_id IS NULL AND ledger_rows.location_id IS NULL))')
            ->where(fn ($query) => $query->where('company_id', auth()->user()?->company_id)->orWhereNull('company_id'))
            ->when($date, fn ($query) => $query->whereDate('posted_at', $inclusive ? '<=' : '<', $date));
    }

    public function ValuationReport(Request $request)
    {
        $validated = $request->validate(['product_id' => ['nullable', 'integer', 'exists:products,id'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'], 'batch_id' => ['nullable', 'integer', 'exists:inventory_batches,id'], 'as_of' => ['nullable', 'date']]);
        $valuation = Product::with(['unit', 'category'])
            ->select('products.*')
            ->when($validated['product_id'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($validated['category_id'] ?? null, fn ($query, $id) => $query->where('category_id', $id))
            ->selectSub(function ($query) use ($validated) {
                $query->from('inventory_cost_layers as layers')
                    ->when($validated['as_of'] ?? null, fn ($layerQuery, $date) => $layerQuery->whereDate('layers.received_at', '<=', $date))
                    ->selectRaw(($validated['as_of'] ?? null)
                        ? 'COALESCE(SUM(GREATEST(layers.original_quantity - (SELECT COALESCE(SUM(consumptions.quantity), 0) FROM inventory_cost_consumptions consumptions LEFT JOIN inventory_movements consumption_movements ON consumption_movements.id = consumptions.movement_id WHERE consumptions.cost_layer_id = layers.id AND ((consumptions.movement_id IS NOT NULL AND DATE(consumption_movements.posted_at) <= ?) OR (consumptions.movement_id IS NULL AND DATE(consumptions.created_at) <= ?))), 0) * layers.unit_cost), 0)'
                        : 'COALESCE(SUM(layers.remaining_quantity * layers.unit_cost), 0)',
                        ($validated['as_of'] ?? null) ? [$validated['as_of'], $validated['as_of']] : [])
                    ->whereColumn('layers.product_id', 'products.id')
                    ->when(!($validated['as_of'] ?? null), fn ($layerQuery) => $layerQuery->where('layers.remaining_quantity', '>', 0))
                    ->when($validated['location_id'] ?? null, fn ($layerQuery, $locationId) => $layerQuery->where('layers.location_id', $locationId))
                    ->when($validated['batch_id'] ?? null, fn ($layerQuery, $batchId) => $layerQuery->where('layers.batch_id', $batchId));
            }, 'ledger_value')
            ->when(($validated['location_id'] ?? null) || ($validated['batch_id'] ?? null) || ($validated['as_of'] ?? null), function ($query) use ($validated) {
                $query->selectSub(function ($movementQuery) use ($validated) {
                    $companyId = auth()->user()?->company_id;
                    $movementQuery->from('inventory_movements')->selectRaw("COALESCE(SUM(CASE WHEN movement_type IN ('opening','receipt','transfer_in','adjustment_in','return_in','quarantine_out','release') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in') THEN -quantity ELSE 0 END), 0)")->whereColumn('product_id', 'products.id')->where(fn ($scope) => $scope->where('inventory_movements.company_id', $companyId)->orWhereNull('inventory_movements.company_id'))->when($validated['location_id'] ?? null, fn ($movementQuery, $locationId) => $movementQuery->where('location_id', $locationId))->when($validated['batch_id'] ?? null, fn ($movementQuery, $batchId) => $movementQuery->where('batch_id', $batchId))->when($validated['as_of'] ?? null, fn ($movementQuery, $date) => $movementQuery->whereDate('posted_at', '<=', $date));
                }, 'filtered_quantity');
            })
            ->orderBy('name')->paginate(50)->withQueryString();

        $products = Product::orderBy('name')->get(['id', 'name']);
        $categories = \App\Models\Category::orderBy('name')->get(['id', 'name']);
        $locations = \App\Models\InventoryLocation::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $batches = \App\Models\InventoryBatch::with('product')->orderBy('batch_no')->get(['id', 'product_id', 'batch_no', 'lot_no']);

        return view('backend.stock.valuation_report', compact('valuation', 'products', 'categories', 'locations', 'batches'));
    }

    public function LocationStockReport(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
        ]);
        $in = "('opening','receipt','transfer_in','adjustment_in','return_in','quarantine_out','release')";
        $out = "('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in')";
        $balances = InventoryMovement::query()
            ->selectRaw("product_id, location_id, SUM(CASE WHEN movement_type IN $in THEN quantity WHEN movement_type IN $out THEN -quantity ELSE 0 END) AS balance")
            ->with(['product', 'location.warehouse'])
            ->whereNotNull('location_id')
            ->when($validated['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($validated['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
            ->groupBy('product_id', 'location_id')
            ->havingRaw('SUM(CASE WHEN movement_type IN '.$in.' THEN quantity WHEN movement_type IN '.$out.' THEN -quantity ELSE 0 END) <> 0')
            ->orderBy('product_id')->paginate(50)->withQueryString();
        $products = Product::orderBy('name')->get(['id', 'name']);
        $locations = InventoryLocation::with('warehouse')->where('is_active', true)->orderBy('code')->get();
        return view('backend.stock.location_stock_report', compact('balances', 'products', 'locations'));
    }

    public function ExpiryReport(Request $request)
    {
        $days = min(3650, max(0, (int) $request->input('days', 90)));
        $cutoff = now()->addDays($days)->toDateString();
        $batches = InventoryBatch::with(['product', 'serials'])
            ->where(fn ($query) => $query->whereDate('expiry_date', '<=', $cutoff)->orWhere(fn ($nested) => $nested->whereNull('expiry_date')->whereDate('best_before_date', '<=', $cutoff)))
            ->where(fn ($query) => $query->whereNotNull('expiry_date')->orWhereNotNull('best_before_date'))
            ->orderByRaw('COALESCE(expiry_date, best_before_date)')->paginate(50)->withQueryString();
        return view('backend.stock.expiry_report', compact('batches', 'days'));
    }

    public function BatchStockReport()
    {
        $in = "('opening','receipt','transfer_in','adjustment_in','return_in','quarantine_out','release')";
        $out = "('issue','transfer_out','adjustment_out','return_out','scrap','quarantine_in')";
        $allocationBatch = 'COALESCE(inventory_movement_allocations.batch_id, inventory_movements.batch_id)';
        $allocationQuantity = 'COALESCE(inventory_movement_allocations.quantity, inventory_movements.quantity)';
        $batches = InventoryMovement::query()
            ->leftJoin('inventory_movement_allocations', 'inventory_movement_allocations.movement_id', '=', 'inventory_movements.id')
            ->selectRaw("inventory_movements.product_id, {$allocationBatch} AS batch_id, inventory_movements.location_id, SUM(CASE WHEN inventory_movements.movement_type IN $in THEN {$allocationQuantity} WHEN inventory_movements.movement_type IN $out THEN -{$allocationQuantity} ELSE 0 END) AS balance")
            ->whereRaw("{$allocationBatch} IS NOT NULL")
            ->with(['product', 'batch', 'location'])
            ->groupBy('inventory_movements.product_id', 'inventory_movement_allocations.batch_id', 'inventory_movements.batch_id', 'inventory_movements.location_id')
            ->havingRaw("SUM(CASE WHEN inventory_movements.movement_type IN {$in} THEN {$allocationQuantity} WHEN inventory_movements.movement_type IN {$out} THEN -{$allocationQuantity} ELSE 0 END) <> 0")
            ->orderBy('batch_id')->paginate(50);
        return view('backend.stock.batch_stock_report', compact('batches'));
    }

    public function SerialStockReport(Request $request)
    {
        $serials = \App\Models\InventorySerial::with(['product', 'batch'])->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))->orderBy('product_id')->paginate(50)->withQueryString();
        return view('backend.stock.serial_stock_report', compact('serials'));
    }

    public function TraceabilityReport(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['nullable', 'integer', $this->owned('products')],
            'batch_id' => ['nullable', 'integer', 'exists:inventory_batches,id'],
            'serial_id' => ['nullable', 'integer', 'exists:inventory_serials,id'],
        ]);
        $companyId = auth()->user()?->company_id;
        if (!empty($validated['batch_id']) && !InventoryBatch::withoutGlobalScopes()->whereKey($validated['batch_id'])->whereHas('product', fn ($query) => $query->withoutGlobalScopes()->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->exists()) abort(404);
        if (!empty($validated['serial_id']) && !\App\Models\InventorySerial::withoutGlobalScopes()->whereKey($validated['serial_id'])->whereHas('product', fn ($query) => $query->withoutGlobalScopes()->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')))->exists()) abort(404);
        $movements = InventoryMovement::with(['product', 'batch', 'serial', 'location', 'creator', 'allocations.batch', 'allocations.serial'])
            ->when($validated['product_id'] ?? null, fn ($query, $id) => $query->where('product_id', $id))
            ->when($validated['batch_id'] ?? null, fn ($query, $id) => $query->where(fn ($nested) => $nested->where('batch_id', $id)->orWhereHas('allocations', fn ($allocation) => $allocation->where('batch_id', $id))))
            ->when($validated['serial_id'] ?? null, fn ($query, $id) => $query->where(fn ($nested) => $nested->where('serial_id', $id)->orWhereHas('allocations', fn ($allocation) => $allocation->where('serial_id', $id))))
            ->latest('posted_at')->latest('id')->paginate(50)->withQueryString();
        $products = Product::orderBy('name')->get(['id', 'name']);
        $batches = InventoryBatch::with('product')->orderBy('batch_no')->get(['id', 'product_id', 'batch_no', 'lot_no']);
        $serials = \App\Models\InventorySerial::with('product')->orderBy('serial_no')->get(['id', 'product_id', 'serial_no']);
        return view('backend.stock.traceability_report', compact('movements', 'products', 'batches', 'serials'));
    }

    private function owned(string $table)
    {
        $companyId = auth()->user()?->company_id;
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
