<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\InventoryStatusBalance;
use App\Models\InventoryStatusTransfer;
use App\Models\Product;
use App\Models\Branch;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\InventoryStatusService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryStatusIntegrationController extends Controller
{
    public function balances(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:blocked,quarantine,damaged,scrap'],
            'product_id' => ['nullable', 'integer'], 'location_id' => ['nullable', 'integer'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->companyScope(InventoryStatusBalance::with(['product', 'location']), $companyId)
            ->where('quantity', '>', 0)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id))
            ->when(array_key_exists('location_id', $data), fn ($q) => $q->where('location_id', $data['location_id']))
            ->when($data['updated_since'] ?? null, fn ($q, $date) => $q->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($query, $request, 'inventory.status-balances', (int) ($data['per_page'] ?? 50));
    }

    public function transfers(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,approved,rejected'],
            'from_status' => ['nullable', 'in:available,blocked,quarantine,damaged'],
            'to_status' => ['nullable', 'in:available,blocked,quarantine,damaged,scrap'],
            'updated_since' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $this->companyScope(InventoryStatusTransfer::with(['product', 'location', 'creator', 'approver', 'inspector']), $companyId)
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['from_status'] ?? null, fn ($q, $status) => $q->where('from_status', $status))
            ->when($data['to_status'] ?? null, fn ($q, $status) => $q->where('to_status', $status))
            ->when($data['updated_since'] ?? null, fn ($q, $date) => $q->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($query, $request, 'inventory.status-transfers', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('inventory_status_transfers', 'external_reference')->where(fn ($q) => $q->where('company_id', $companyId))],
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))],
            'location_id' => ['nullable', 'integer', \App\Services\InventoryLocationRuleService::existsForCompany($companyId)],
            'from_status' => ['required', 'in:available,blocked,quarantine,damaged'],
            'to_status' => ['required', 'in:available,blocked,quarantine,damaged,scrap', 'different:from_status'],
            'quantity' => ['required', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'max:2000'], 'inspection_required' => ['nullable', 'boolean'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(InventoryStatusTransfer::query(), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load('product', 'location'), 'status' => 'duplicate_ignored']);
        }
        $transfer = DB::transaction(function () use ($data, $companyId, $request): InventoryStatusTransfer {
            $transfer = InventoryStatusTransfer::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'transfer_no' => app(NumberingSequenceService::class)->nextOrFallback('inventory_status_transfer', 'ST-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'product_id' => $data['product_id'], 'location_id' => $data['location_id'] ?? null,
                'from_status' => $data['from_status'], 'to_status' => $data['to_status'], 'quantity' => $data['quantity'],
                'reason' => $data['reason'], 'inspection_required' => (bool) ($data['inspection_required'] ?? false), 'inspection_status' => !empty($data['inspection_required']) ? 'pending' : 'not_required', 'status' => 'pending', 'created_by' => $request->user()?->id,
            ]);
            app(AuditService::class)->record('inventory_status_transfer.created', $transfer, null, $transfer->toArray());
            return $transfer;
        });
        return response()->json(['data' => $transfer->load('product', 'location'), 'status' => 'pending_approval'], 201);
    }

    public function approve(int $id): JsonResponse
    {
        app(\App\Services\ApprovalGuard::class)->assertBeforeTransaction(InventoryStatusTransfer::class, $id);
        $transfer = DB::transaction(function () use ($id): InventoryStatusTransfer {
            $transfer = $this->companyScope(InventoryStatusTransfer::query(), auth()->user()?->company_id)->lockForUpdate()->findOrFail($id);
            if ($transfer->status !== 'pending') throw new \RuntimeException('Only pending status transfers can be approved.');
            app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
            app(InventoryStatusService::class)->apply($transfer);
            $transfer->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
            app(AuditService::class)->record('inventory_status_transfer.approved', $transfer, ['status' => 'pending'], ['status' => 'approved']);
            return $transfer->fresh();
        });
        return response()->json(['data' => $transfer->load('product', 'location'), 'status' => $transfer->status]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $transfer = $this->companyScope(InventoryStatusTransfer::query(), auth()->user()?->company_id)->findOrFail($id);
        if ($transfer->status !== 'pending') abort(422, 'Only pending status transfers can be rejected.');
        app(\App\Services\ApprovalGuard::class)->assertDifferent($transfer);
        $before = $transfer->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
        $transfer->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => auth()->id(), 'rejected_at' => now()]);
        app(AuditService::class)->record('inventory_status_transfer.rejected', $transfer, $before, $transfer->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']));
        return response()->json(['data' => $transfer->fresh()->load('product', 'location'), 'status' => 'rejected']);
    }

    public function inspect(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['inspection_status' => ['required', 'in:passed,failed'], 'inspection_notes' => ['required', 'string', 'max:3000']]);
        try {
            $transfer = $this->companyScope(InventoryStatusTransfer::query(), auth()->user()?->company_id)->findOrFail($id);
            $transfer = app(InventoryStatusService::class)->inspect($transfer, $data['inspection_status'], $data['inspection_notes'], auth()->id());
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $transfer, 'status' => $transfer->inspection_status]);
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
