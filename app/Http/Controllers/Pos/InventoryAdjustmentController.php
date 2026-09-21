<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\InventoryAdjustmentRequest;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\Product;
use App\Models\InventoryBatch;
use App\Models\InventorySerial;
use App\Models\InventoryLocation;
use App\Services\AuditService;
use App\Services\InventoryLedgerService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryAdjustmentController extends Controller
{
    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function companyAdjustment(int $id): InventoryAdjustment
    {
        return InventoryAdjustment::where('company_id', $this->companyId())->findOrFail($id);
    }

    public function index()
    {
        $adjustments = InventoryAdjustment::where('company_id', $this->companyId())->with(['creator', 'approver'])->latest()->paginate(30);
        return view('backend.stock.adjustment_all', compact('adjustments'));
    }

    public function create()
    {
        $products = Product::where(fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->orderBy('name')->get();
        $opening = false;
        $locations = InventoryLocation::where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->orderBy('code')->get();
        return view('backend.stock.adjustment_add', compact('products', 'locations', 'opening'));
    }

    public function openingCreate()
    {
        $products = Product::where(fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->orderBy('name')->get();
        $opening = true;
        $locations = InventoryLocation::where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->orderBy('code')->get();
        return view('backend.stock.adjustment_add', compact('products', 'locations', 'opening'));
    }

    public function openingStore(InventoryAdjustmentRequest $request)
    {
        $request->merge(['reason_code' => 'opening_stock']);
        foreach ($request->direction as $direction) {
            if ($direction !== 'in') {
                return back()->withErrors(['direction' => 'Opening stock can only increase inventory.'])->withInput();
            }
        }
        return $this->store($request);
    }

    public function store(InventoryAdjustmentRequest $request)
    {
        foreach ($request->input('location_id', []) as $locationId) {
            if ($locationId && !InventoryLocation::where('is_active', true)->whereHas('warehouse.branch', fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->find($locationId)) return back()->withErrors(['location_id' => 'Selected inventory location is inactive or outside the current company.'])->withInput();
        }
        $adjustment = DB::transaction(function () use ($request): InventoryAdjustment {
            $adjustment = InventoryAdjustment::create([
                'company_id' => auth()->user()?->company_id,
                'adjustment_no' => $request->adjustment_no ?: app(NumberingSequenceService::class)->nextOrFallback('inventory_adjustment', 'ADJ-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id),
                'date' => $request->date,
                'reason_code' => $request->reason_code,
                'description' => $request->description,
                'created_by' => auth()->id(),
            ]);

            foreach ($request->product_id as $index => $productId) {
                InventoryAdjustmentLine::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $productId,
                    'location_id' => $request->location_id[$index] ?? null,
                    'direction' => $request->direction[$index],
                    'quantity' => $request->quantity[$index],
                    'unit_cost' => $request->unit_cost[$index] ?? null,
                    'batch_no' => $request->batch_no[$index] ?? null,
                    'serial_numbers' => $request->serial_numbers[$index] ?? null,
                    'manufacturing_date' => $request->manufacturing_date[$index] ?? null,
                    'expiry_date' => $request->expiry_date[$index] ?? null,
                    'best_before_date' => $request->best_before_date[$index] ?? null,
                    'warranty_until' => $request->warranty_until[$index] ?? null,
                ]);
            }

            app(AuditService::class)->record('inventory_adjustment.created', $adjustment, null, $adjustment->toArray());
            return $adjustment;
        });

        return redirect()->route('inventory.adjustments')->with(['message' => 'Inventory adjustment submitted for approval.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryAdjustment::class, $id);
        try {
            DB::transaction(function () use ($id): void {
                $adjustment = InventoryAdjustment::where('company_id', $this->companyId())->with('lines')->lockForUpdate()->findOrFail($id);
                if ($adjustment->status !== 'pending') {
                    throw new \RuntimeException('This adjustment has already been processed.');
                }
                app(\App\Services\ApprovalGuard::class)->assertDifferent($adjustment);

                foreach ($adjustment->lines as $line) {
                    $product = Product::where(fn ($query) => $query->where('company_id', $this->companyId())->orWhereNull('company_id'))->lockForUpdate()->findOrFail($line->product_id);
                    $quantity = (float) $line->quantity;
                    if ($line->direction === 'out' && app(\App\Services\InventoryAvailabilityService::class)->available($product, true, $line->location_id, $adjustment->company_id) < $quantity) {
                        throw new \RuntimeException('Insufficient stock for '.$product->name.'.');
                    }
                    $batch = null;
                    $batchTracked = in_array($product->tracking_type, ['batch', 'lot'], true);
                    if ($line->batch_no) {
                        $batch = $line->direction === 'in'
                            ? InventoryBatch::firstOrCreate(['product_id' => $product->id, 'batch_no' => $line->batch_no], ['location_id' => $line->location_id, 'manufacturing_date' => $line->manufacturing_date, 'expiry_date' => $line->expiry_date, 'best_before_date' => $line->best_before_date, 'warranty_until' => $line->warranty_until])
                            : InventoryBatch::where('product_id', $product->id)->where('batch_no', $line->batch_no)->first();
                    }
                    if ($batchTracked && $line->direction === 'out') {
                        if (!$batch) throw new \RuntimeException('A valid batch/lot number is required for '.$product->name.'.');
                        $batchBalance = \App\Models\InventoryMovement::where('product_id', $product->id)
                            ->where('batch_id', $batch->id)
                            ->when($line->location_id, fn ($query) => $query->where('location_id', $line->location_id))
                            ->selectRaw("COALESCE(SUM(CASE WHEN movement_type IN ('receipt','opening','transfer_in','adjustment_in','return_in') THEN quantity WHEN movement_type IN ('issue','transfer_out','adjustment_out','return_out','scrap') THEN -quantity ELSE 0 END), 0) AS balance")
                            ->value('balance');
                        if ((float) $batchBalance < $quantity) throw new \RuntimeException('Insufficient stock in batch '.$batch->batch_no.' for '.$product->name.'.');
                    }
                    $serialNumbers = $line->serial_numbers ? array_values(array_filter(array_map('trim', preg_split('/[,\r\n]+/', $line->serial_numbers)))) : [];
                    $serials = [];
                    if ($product->tracking_type === 'serial' && $line->direction === 'in' && $adjustment->reason_code === 'opening_stock' && !$serialNumbers) throw new \RuntimeException('Serial numbers are required for opening stock of '.$product->name.'.');
                    if (in_array($product->tracking_type, ['batch', 'lot'], true) && $line->direction === 'in' && $adjustment->reason_code === 'opening_stock' && !$line->batch_no) throw new \RuntimeException('Batch/lot number is required for opening stock of '.$product->name.'.');
                    if ($product->tracking_type === 'serial' && $line->direction === 'out') {
                        if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Serial numbers are required and must equal quantity for '.$product->name.'.');
                        $serials = app(\App\Services\SerialLifecycleService::class)->issueSpecific($product, $serialNumbers, $line->location_id, $batch?->id);
                    }
                    if ($product->tracking_type === 'serial' && $line->direction === 'in' && $serialNumbers) {
                        if (count($serialNumbers) !== (int) round($quantity)) throw new \RuntimeException('Serial count must equal quantity for '.$product->name.'.');
                        foreach ($serialNumbers as $serialNo) $serials[] = app(\App\Services\SerialLifecycleService::class)->receive($product, $serialNo, $line->location_id, $batch?->id, $line->warranty_until);
                    }
                    $product->quantity = (float) $product->quantity + ($line->direction === 'in' ? $quantity : -$quantity);
                    $product->save();
                    $movementType = $adjustment->reason_code === 'opening_stock'
                        ? 'opening'
                        : ($line->direction === 'in' ? 'adjustment_in' : 'adjustment_out');
                    if ($serials) foreach ($serials as $serial) app(InventoryLedgerService::class)->post($product->id, $movementType, 1, $line->unit_cost ? (float) $line->unit_cost : null, $line->location_id, $adjustment, $adjustment->reason_code, null, $batch?->id, $serial->id);
                    else app(InventoryLedgerService::class)->post($product->id, $movementType, $quantity, $line->unit_cost ? (float) $line->unit_cost : null, $line->location_id, $adjustment, $adjustment->reason_code, null, $batch?->id);
                }

                $adjustment->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(AuditService::class)->record('inventory_adjustment.approved', $adjustment, ['status' => 'pending'], ['status' => 'approved']);
            });
        } catch (\RuntimeException $exception) {
            return redirect()->back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }

        return redirect()->route('inventory.adjustments')->with(['message' => 'Inventory adjustment approved.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $adjustment = $this->companyAdjustment($id);
        if ($adjustment->status !== 'pending') {
            return redirect()->back()->with(['message' => 'This adjustment has already been processed.', 'alert-type' => 'error']);
        }
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($adjustment); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $adjustment->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $adjustment->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('inventory_adjustment.rejected', $adjustment, $before, $adjustment->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return redirect()->route('inventory.adjustments')->with(['message' => 'Inventory adjustment rejected.', 'alert-type' => 'success']);
    }
}
