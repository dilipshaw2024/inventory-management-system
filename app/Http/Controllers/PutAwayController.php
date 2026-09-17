<?php

namespace App\Http\Controllers;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\NumberingSequenceService;
use App\Services\InventoryLocationCapacityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PutAwayController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['product_id' => ['nullable', 'exists:products,id'], 'quantity' => ['nullable', 'numeric', 'gt:0']]);
        $quantity = (float) ($data['quantity'] ?? 0);
        $product = !empty($data['product_id']) ? Product::find($data['product_id']) : null;
        $locations = InventoryLocation::with('warehouse')->where('is_active', true)->whereIn('type', ['bin', 'shelf', 'rack'])->get()->map(function (InventoryLocation $location) use ($quantity, $product): InventoryLocation {
            $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
            $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
            $occupied = (float) $location->movements()->selectRaw('COALESCE(SUM(CASE WHEN movement_type IN ('.implode(',', array_fill(0, count($in), '?')).') THEN quantity WHEN movement_type IN ('.implode(',', array_fill(0, count($out), '?')).') THEN -quantity ELSE 0 END), 0) AS balance', array_merge($in, $out))->value('balance');
            $location->setAttribute('occupied_quantity', max(0, $occupied));
            $location->setAttribute('available_capacity', $location->capacity === null ? null : max(0, (float) $location->capacity - $occupied));
            $physicalCapacityOk = true;
            if ($product && $quantity > 0) {
                try { app(InventoryLocationCapacityService::class)->assertCanReceive($location, $product, $quantity); }
                catch (\RuntimeException) { $physicalCapacityOk = false; }
            }
            $location->setAttribute('can_receive', ($location->capacity === null || (float) $location->capacity - $occupied >= $quantity) && $physicalCapacityOk);
            return $location;
        })->filter(fn (InventoryLocation $location): bool => $location->can_receive)->sortBy(fn (InventoryLocation $location) => $location->available_capacity === null ? PHP_FLOAT_MAX : $location->available_capacity)->values();
        $products = Product::where('status', 1)->orderBy('name')->get();
        return view('backend.stock.put_away', compact('locations', 'products', 'quantity'));
    }

    public function confirm(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $data = $request->validate([
            'product_id' => ['required', 'integer', $productScope],
            'source_location_id' => ['required', 'integer', $locationScope],
            'destination_location_id' => ['required', 'integer', 'different:source_location_id', $locationScope],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        try {
            $locations = InventoryLocation::with('warehouse')->whereIn('id', [$data['source_location_id'], $data['destination_location_id']])->get()->keyBy('id');
            $product = Product::findOrFail($data['product_id']);
            $destination = $locations[$data['destination_location_id']] ?? null;
            if ($locations->count() !== 2 || (int) $locations[$data['source_location_id']]->warehouse_id !== (int) $destination?->warehouse_id) throw new \RuntimeException('Put-away source and destination must belong to the same warehouse.');
            if (!$destination || !in_array($destination->type, ['bin', 'shelf', 'rack'], true)) throw new \RuntimeException('Put-away destination must be a rack, shelf, or bin.');
            app(InventoryLocationCapacityService::class)->assertCanReceive($destination, $product, (float) $data['quantity']);
            $transfer = DB::transaction(function () use ($data): InventoryTransfer {
                $transfer = InventoryTransfer::create([
                    'transfer_no' => app(NumberingSequenceService::class)->nextOrFallback('inventory_transfer', 'PUT-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
                    'date' => now()->toDateString(),
                    'description' => 'Put-away task created from the capacity planner; approval required.',
                    'created_by' => auth()->id(),
                ]);
                InventoryTransferLine::create(['transfer_id' => $transfer->id, 'product_id' => $data['product_id'], 'source_location_id' => $data['source_location_id'], 'destination_location_id' => $data['destination_location_id'], 'quantity' => $data['quantity']]);
                app(AuditService::class)->record('put_away.task_created', $transfer, null, $transfer->toArray());
                return $transfer;
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['put_away' => $exception->getMessage()])->withInput();
        }
        return redirect()->route('inventory.transfers')->with(['message' => 'Put-away transfer '.$transfer->transfer_no.' submitted for approval.', 'alert-type' => 'success']);
    }
}
