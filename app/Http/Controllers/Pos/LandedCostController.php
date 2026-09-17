<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\LandedCost;
use App\Services\AuditService;
use App\Services\LandedCostService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;

class LandedCostController extends Controller
{
    public function index()
    {
        $costs = LandedCost::with('goodsReceipt')->latest()->paginate(30);
        return view('backend.purchase.landed_costs', compact('costs'));
    }

    public function create()
    {
        $receipts = GoodsReceipt::where('status', 'approved')->with(['purchaseOrder.supplier', 'lines.product'])->latest()->get();
        return view('backend.purchase.landed_cost_add', compact('receipts'));
    }

    public function store(Request $request)
    {
        $data = $request->validate(['cost_no' => ['nullable', 'string', 'max:100', 'unique:landed_costs,cost_no'], 'goods_receipt_id' => ['required', 'exists:goods_receipts,id'], 'cost_type' => ['required', 'string', 'max:50'], 'amount' => ['required', 'numeric', 'gt:0'], 'allocation_method' => ['required', 'in:by_value,by_quantity'], 'description' => ['nullable', 'string', 'max:2000']]);
        $receipt = GoodsReceipt::findOrFail($data['goods_receipt_id']);
        if ($receipt->status !== 'approved') return back()->withInput()->with(['message' => 'Landed costs require an approved goods receipt.', 'alert-type' => 'error']);
        $cost = LandedCost::create($data + ['company_id' => $receipt->company_id ?: auth()->user()?->company_id, 'cost_no' => $data['cost_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('landed_cost', 'LC-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'status' => 'pending', 'created_by' => auth()->id()]);
        app(AuditService::class)->record('landed_cost.created', $cost, null, $cost->toArray());
        return redirect()->route('procurement.landed.costs.index')->with(['message' => 'Landed cost submitted for approval.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        try { app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(LandedCost::class, $id); $cost = LandedCost::with('allocations')->findOrFail($id); if ($cost->status !== 'pending') throw new \RuntimeException('This landed cost has already been processed.'); app(\App\Services\ApprovalGuard::class)->assertDifferent($cost); app(LandedCostService::class)->approve($cost); app(AuditService::class)->record('landed_cost.approved', $cost, ['status' => 'pending'], ['status' => 'approved']); return back()->with(['message' => 'Landed cost allocated to inventory layers.', 'alert-type' => 'success']); }
        catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);

        try {
            $cost = LandedCost::findOrFail($id);
            if ($cost->status !== 'pending') throw new \RuntimeException('Only pending landed costs can be rejected.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($cost);
            $before = $cost->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
            $cost->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
            app(AuditService::class)->record('landed_cost.rejected', $cost, $before, $cost->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
            return back()->with(['message' => 'Landed cost rejected without changing inventory valuation.', 'alert-type' => 'success']);
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
    }

    public function reverse(Request $request, int $id)
    {
        $data = $request->validate(['reversal_reason' => ['required', 'string', 'max:2000']]);
        try {
            $cost = LandedCost::findOrFail($id);
            app(LandedCostService::class)->reverse($cost, $data['reversal_reason']);
            app(AuditService::class)->record('landed_cost.reversed', $cost, ['status' => 'approved'], $cost->fresh()->only(['status', 'reversal_reason', 'reversed_by', 'reversed_at']));
            return back()->with(['message' => 'Landed cost valuation and linked journal were reversed.', 'alert-type' => 'success']);
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
    }
}
