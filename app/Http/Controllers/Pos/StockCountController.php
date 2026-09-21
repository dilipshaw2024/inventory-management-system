<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockCountSchedule;
use App\Models\InventoryMovement;
use App\Models\Branch;
use App\Models\User;
use App\Models\StockCountAssignment;
use App\Services\AuditService;
use App\Services\InventoryLedgerService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockCountController extends Controller
{
    public function index()
    {
        $counts = StockCount::with(['location', 'creator', 'assignments.user', 'assignments.assigner'])->latest()->paginate(30);
        $schedules = StockCountSchedule::with('location')->latest()->get();
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get();
        $counterUsers = User::where('company_id', auth()->user()?->company_id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']);
        return view('backend.stock.count_all', compact('counts', 'schedules', 'locations', 'counterUsers'));
    }

    public function create()
    {
        $products = Product::where('status', 1)->orderBy('name')->get();
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get();
        return view('backend.stock.count_add', compact('products', 'locations'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $ownedLocation = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $ownedProduct = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $data = $request->validate([
            'count_no' => ['nullable', 'string', 'max:50', Rule::unique('stock_counts', 'count_no')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'count_date' => ['required', 'date'],
            'location_id' => ['nullable', 'integer', $ownedLocation],
            'description' => ['nullable', 'string'],
            'product_id' => ['required', 'array', 'min:1'],
            'product_id.*' => ['required', 'integer', $ownedProduct],
            'counted_quantity' => ['required', 'array'],
            'counted_quantity.*' => ['required', 'numeric', 'min:0'],
        ]);
        DB::transaction(function () use ($data): void {
            $count = StockCount::create(collect($data)->except(['product_id', 'counted_quantity'])->all() + ['count_no' => $data['count_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('stock_count', 'CNT-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'status' => 'submitted', 'created_by' => auth()->id()]);
            foreach ($data['product_id'] as $index => $productId) {
                $product = Product::findOrFail($productId);
                $systemQuantity = $count->location_id ? $this->locationBalance($productId, (int) $count->location_id) : (float) $product->quantity;
                StockCountLine::create(['stock_count_id' => $count->id, 'product_id' => $productId, 'system_quantity' => $systemQuantity, 'counted_quantity' => $data['counted_quantity'][$index], 'variance_quantity' => (float) $data['counted_quantity'][$index] - $systemQuantity, 'unit_cost' => $product->purchase_price]);
            }
            app(AuditService::class)->record('stock_count.created', $count, null, $count->toArray());
        });
        return redirect()->route('inventory.counts.index')->with(['message' => 'Stock count submitted for approval.', 'alert-type' => 'success']);
    }

    public function storeSchedule(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $ownedLocation = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $data = $request->validate(['schedule_no' => ['nullable', 'string', 'max:80', Rule::unique('stock_count_schedules', 'schedule_no')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))], 'location_id' => ['nullable', 'integer', $ownedLocation], 'frequency_days' => ['required', 'integer', 'min:1', 'max:3650'], 'next_due' => ['required', 'date'], 'description' => ['nullable', 'string', 'max:2000']]);
        if (!empty($data['location_id'])) InventoryLocation::findOrFail($data['location_id']);
        $schedule = StockCountSchedule::create($data + ['schedule_no' => $data['schedule_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('stock_count_schedule', 'CNS-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'created_by' => auth()->id(), 'is_active' => true]);
        app(AuditService::class)->record('stock_count_schedule.created', $schedule, null, $schedule->toArray());
        return back()->with(['message' => 'Recurring stock-count schedule created.', 'alert-type' => 'success']);
    }

    public function edit(int $id)
    {
        $count = StockCount::with('lines.product')->findOrFail($id);
        if ($count->status !== 'draft' && !($count->status === 'submitted' && $count->recount_required)) return back()->with(['message' => 'This stock count is not awaiting a count or recount.', 'alert-type' => 'error']);
        return view('backend.stock.count_edit', compact('count'));
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate(['counted_quantity' => ['required', 'array', 'min:1'], 'counted_quantity.*' => ['required', 'numeric', 'min:0']]);
        try {
            DB::transaction(function () use ($data, $id): void {
                $count = StockCount::with('lines')->lockForUpdate()->findOrFail($id);
                if (!in_array($count->status, ['draft', 'submitted'], true)) throw new \RuntimeException('This stock count cannot be counted again.');
                if ($count->status === 'submitted' && !$count->recount_required) throw new \RuntimeException('A recount must be requested before entering a second count.');
                $wasRecount = $count->status === 'submitted' && $count->recount_required;
                $beforeStatus = $count->status;
                foreach ($count->lines as $line) {
                    if (!array_key_exists($line->product_id, $data['counted_quantity'])) throw new \RuntimeException('A counted quantity is missing.');
                    $counted = (float) $data['counted_quantity'][$line->product_id];
                    if ($count->status === 'draft') {
                        $line->update(['counted_quantity' => $counted, 'variance_quantity' => $counted - (float) $line->system_quantity]);
                    } else {
                        if ((int) $count->created_by === (int) auth()->id()) throw new \RuntimeException('The original counter cannot perform the recount.');
                        $line->update(['recounted_quantity' => $counted, 'recounted_by' => auth()->id(), 'recounted_at' => now()]);
                    }
                }
                $count->update(['status' => 'submitted']);
                app(AuditService::class)->record($wasRecount ? 'stock_count.recounted' : 'stock_count.submitted', $count, ['status' => $beforeStatus, 'recount_required' => $count->recount_required], ['status' => 'submitted', 'recount_required' => $count->recount_required]);
            });
            return redirect()->route('inventory.counts.index')->with(['message' => 'Stock count submitted for approval.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) { return back()->withInput()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function requestRecount(Request $request, int $id)
    {
        $data = $request->validate(['recount_reason' => ['required', 'string', 'max:2000']]);
        $count = StockCount::with('lines')->findOrFail($id);
        if ($count->status !== 'submitted') return back()->with(['message' => 'Only submitted counts can be sent for recount.', 'alert-type' => 'error']);
        if ((int) $count->created_by === (int) auth()->id()) return back()->with(['message' => 'The count creator cannot request their own recount.', 'alert-type' => 'error']);
        $count->update(['recount_required' => true, 'recount_requested_by' => auth()->id(), 'recount_requested_at' => now(), 'recount_reason' => $data['recount_reason']]);
        $count->lines()->update(['recounted_quantity' => null, 'recounted_by' => null, 'recounted_at' => null]);
        app(AuditService::class)->record('stock_count.recount_requested', $count, ['recount_required' => false], $count->fresh()->only(['recount_required', 'recount_requested_by', 'recount_requested_at', 'recount_reason']));
        return back()->with(['message' => 'Recount requested. A different user must enter the second count.', 'alert-type' => 'success']);
    }

    public function assignCounters(Request $request, int $id)
    {
        $companyId = auth()->user()?->company_id;
        $data = $request->validate(['counter_user_ids' => ['required', 'array', 'min:1', 'max:20'], 'counter_user_ids.*' => ['required', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->where('is_active', true))]]);
        if (collect($data['counter_user_ids'])->duplicates()->isNotEmpty()) return back()->with(['message' => 'Each counter can only be assigned once.', 'alert-type' => 'error']);
        try {
            DB::transaction(function () use ($companyId, $data, $id): void {
                $count = StockCount::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
                if ($count->status !== 'submitted') throw new \RuntimeException('Only submitted counts can have counters assigned.');
                foreach ($data['counter_user_ids'] as $userId) {
                    $assignment = $count->assignments()->where('user_id', $userId)->first();
                    if ($assignment?->status === 'completed') throw new \RuntimeException('A completed counter assignment cannot be reassigned.');
                    $count->assignments()->updateOrCreate(['user_id' => $userId], ['company_id' => $companyId, 'assigned_by' => auth()->id(), 'status' => 'assigned', 'assigned_at' => now(), 'completed_at' => null]);
                }
                $count->assignments()->where('status', 'assigned')->whereNotIn('user_id', array_map('intval', $data['counter_user_ids']))->update(['status' => 'revoked', 'completed_at' => null]);
                app(AuditService::class)->record('stock_count.counters_assigned', $count, null, ['counter_user_ids' => array_map('intval', $data['counter_user_ids'])]);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Stock-count counters assigned.', 'alert-type' => 'success']);
    }

    public function completeCounter(int $id, int $assignmentId)
    {
        try {
            DB::transaction(function () use ($id, $assignmentId): void {
                $assignment = StockCountAssignment::where('id', $assignmentId)->where('stock_count_id', $id)->where('company_id', auth()->user()?->company_id)->lockForUpdate()->firstOrFail();
                if ((int) $assignment->user_id !== (int) auth()->id()) throw new \RuntimeException('Only the assigned counter can complete this assignment.');
                if ($assignment->status !== 'assigned') throw new \RuntimeException('This counter assignment is not active.');
                $assignment->update(['status' => 'completed', 'completed_at' => now()]);
                app(AuditService::class)->record('stock_count.counter_completed', $assignment, ['status' => 'assigned'], ['status' => 'completed', 'completed_at' => now()->toISOString()]);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Counter assignment completed.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(StockCount::class, $id);
        try {
            DB::transaction(function () use ($id): void {
                $count = StockCount::with('lines')->lockForUpdate()->findOrFail($id);
                if ($count->status !== 'submitted') throw new \RuntimeException('This stock count has already been processed.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($count);
                $varianceRequiringRecount = app(\App\Services\StockCountVariancePolicyService::class)->requiringRecount($count);
                if ($varianceRequiringRecount) throw new \RuntimeException('Stock-count variance exceeds the configured '.$varianceRequiringRecount['threshold_percent'].'% recount threshold; request an independent recount before approval.');
                foreach ($count->lines as $line) {
                    if ($count->recount_required && $line->recounted_quantity === null) throw new \RuntimeException('Every line must have a completed recount before approval.');
                    $product = Product::lockForUpdate()->findOrFail($line->product_id);
                    $systemQuantity = $count->location_id ? $this->locationBalance($line->product_id, (int) $count->location_id) : (float) $product->quantity;
                    $countedQuantity = $count->recount_required ? (float) $line->recounted_quantity : (float) $line->counted_quantity;
                    $variance = $countedQuantity - $systemQuantity;
                    if (abs($variance) < 0.000001) continue;
                    $product->quantity = (float) $product->quantity + $variance;
                    $product->save();
                    app(InventoryLedgerService::class)->post($product->id, $variance > 0 ? 'adjustment_in' : 'adjustment_out', abs($variance), (float) ($line->unit_cost ?? $product->purchase_price ?? 0), $count->location_id, $count, 'Physical stock count variance');
                    $line->update(['system_quantity' => $systemQuantity, 'variance_quantity' => $variance]);
                }
                $count->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(AuditService::class)->record('stock_count.approved', $count, ['status' => 'submitted'], ['status' => 'approved']);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Stock count approved and variances posted.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $count = StockCount::findOrFail($id);
        if ($count->status !== 'submitted') return back()->with(['message' => 'Only submitted stock counts can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($count); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $count->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $count->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('stock_count.rejected', $count, $before, $count->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Stock count rejected.', 'alert-type' => 'success']);
    }

    private function locationBalance(int $productId, int $locationId): float
    {
        $in = ['opening', 'receipt', 'transfer_in', 'adjustment_in', 'return_in', 'quarantine_out', 'release'];
        $out = ['issue', 'transfer_out', 'adjustment_out', 'return_out', 'scrap', 'quarantine_in'];
        return (float) InventoryMovement::where('product_id', $productId)->where('location_id', $locationId)->get()->sum(fn ($movement) => in_array($movement->movement_type, $in, true) ? (float) $movement->quantity : (in_array($movement->movement_type, $out, true) ? -(float) $movement->quantity : 0));
    }
}
