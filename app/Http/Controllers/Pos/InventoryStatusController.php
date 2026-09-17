<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryStatusBalance;
use App\Models\InventoryStatusTransfer;
use App\Models\Product;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\InventoryStatusService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryStatusController extends Controller
{
    public function index()
    {
        $transfers = InventoryStatusTransfer::with(['product', 'location'])->latest()->paginate(30);
        $balances = InventoryStatusBalance::with(['product', 'location'])->where('quantity', '>', 0)->orderBy('status')->paginate(30, ['*'], 'balances');
        return view('backend.stock.status_transfers', compact('transfers', 'balances'));
    }

    public function create()
    {
        $products = Product::where('status', 1)->orderBy('name')->get();
        $locations = InventoryLocation::where('is_active', true)->orderBy('code')->get();
        return view('backend.stock.status_transfer_add', compact('products', 'locations'));
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        $productScope = Rule::exists('products', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'));
        $locationScope = \App\Services\InventoryLocationRuleService::existsForCompany($companyId);
        $data = $request->validate([
            'transfer_no' => ['nullable', 'string', 'max:50', 'unique:inventory_status_transfers,transfer_no'],
            'product_id' => ['required', 'integer', $productScope], 'location_id' => ['nullable', 'integer', $locationScope],
            'from_status' => ['required', 'in:available,blocked,quarantine,damaged'], 'to_status' => ['required', 'in:available,blocked,quarantine,damaged,scrap', 'different:from_status'],
            'quantity' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string'], 'inspection_required' => ['nullable', 'boolean'],
        ]);
        $inspectionRequired = (bool) ($data['inspection_required'] ?? false);
        $transfer = InventoryStatusTransfer::create($data + ['inspection_required' => $inspectionRequired, 'inspection_status' => $inspectionRequired ? 'pending' : 'not_required', 'transfer_no' => $data['transfer_no'] ?: app(NumberingSequenceService::class)->nextOrFallback('inventory_status_transfer', 'ST-'.now()->format('YmdHis').'-'.random_int(100, 999), auth()->user()?->company_id, auth()->user()?->branch_id), 'status' => 'pending', 'created_by' => auth()->id()]);
        app(AuditService::class)->record('inventory_status_transfer.created', $transfer, null, $transfer->toArray());
        return redirect()->route('inventory.status.index')->with(['message' => 'Status transfer submitted for approval.', 'alert-type' => 'success']);
    }

    public function approve(int $id)
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryStatusTransfer::class, $id);
        try {
            DB::transaction(function () use ($id): void {
                $transfer = InventoryStatusTransfer::lockForUpdate()->findOrFail($id);
                if ($transfer->status !== 'pending') throw new \RuntimeException('This status transfer has already been processed.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
                app(InventoryStatusService::class)->apply($transfer);
                $transfer->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
                app(AuditService::class)->record('inventory_status_transfer.approved', $transfer, ['status' => 'pending'], ['status' => 'approved']);
            });
        } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        return back()->with(['message' => 'Stock status updated.', 'alert-type' => 'success']);
    }

    public function reject(Request $request, int $id)
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $transfer = InventoryStatusTransfer::findOrFail($id);
        if ($transfer->status !== 'pending') return back()->with(['message' => 'Only pending status transfers can be rejected.', 'alert-type' => 'error']);
        try { app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer); } catch (\RuntimeException $exception) { return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']); }
        $before = $transfer->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $transfer->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('inventory_status_transfer.rejected', $transfer, $before, $transfer->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return back()->with(['message' => 'Status transfer rejected.', 'alert-type' => 'success']);
    }

    public function inspect(Request $request, int $id)
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        try {
            $transfer = InventoryStatusTransfer::findOrFail($id);
            app(InventoryStatusService::class)->inspect($transfer, $data['inspection_status'], $data['inspection_notes'], auth()->id());
        } catch (\RuntimeException $exception) {
            return back()->with(['message' => $exception->getMessage(), 'alert-type' => 'error']);
        }
        return back()->with(['message' => 'Status-transfer inspection recorded.', 'alert-type' => 'success']);
    }
}
