<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionLine;
use App\Models\Supplier;
use App\Services\ApprovalGuard;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,submitted,approved,rejected,converted'],
            'supplier_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $requisitions = $this->companyScope(PurchaseRequisition::with(['supplier', 'lines.product']), $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('suggested_supplier_id', $supplierId))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($requisitions, $request, 'purchasing.requisitions', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'], 'requested_date' => ['required', 'date'],
            'required_date' => ['nullable', 'date', 'after_or_equal:requested_date'], 'suggested_supplier_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'], 'lines.*.requested_qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.estimated_unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(PurchaseRequisition::with(['supplier', 'lines.product']), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        if (!empty($data['suggested_supplier_id']) && !$this->companyScope(Supplier::query(), $companyId)->whereKey($data['suggested_supplier_id'])->where('is_active', true)->exists()) abort(422, 'Suggested supplier is not authorized or inactive.');
        $productIds = collect($data['lines'])->pluck('product_id');
        if ($this->companyScope(Product::query(), $companyId)->whereIn('id', $productIds)->count() !== $productIds->unique()->count()) abort(422, 'One or more products are not authorized for this company.');
        $requisition = DB::transaction(function () use ($data, $companyId, $request): PurchaseRequisition {
            $requisition = PurchaseRequisition::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'requisition_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_requisition', 'PR-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'requested_date' => $data['requested_date'], 'required_date' => $data['required_date'] ?? null,
                'suggested_supplier_id' => $data['suggested_supplier_id'] ?? null, 'description' => $data['description'] ?? null,
                'created_by' => $request->user()?->id, 'status' => 'submitted',
            ]);
            foreach ($data['lines'] as $line) PurchaseRequisitionLine::create(['purchase_requisition_id' => $requisition->id, 'product_id' => $line['product_id'], 'requested_qty' => $line['requested_qty'], 'estimated_unit_price' => $line['estimated_unit_price'], 'notes' => $line['notes'] ?? null]);
            app(AuditService::class)->record('purchase_requisition.created', $requisition, null, $requisition->toArray() + ['api' => true]);
            return $requisition->fresh(['supplier', 'lines.product']);
        });
        return response()->json(['data' => $requisition, 'status' => 'submitted'], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $requisition = DB::transaction(function () use ($id, $request): PurchaseRequisition {
                $requisition = $this->companyScope(PurchaseRequisition::query(), $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($requisition->status !== 'submitted') throw new \RuntimeException('Only submitted requisitions can be approved.');
                app(ApprovalGuard::class)->assertDifferent($requisition);
                $before = $requisition->only(['status', 'approved_by', 'approved_at']);
                $requisition->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now()]);
                app(AuditService::class)->record('purchase_requisition.approved', $requisition, $before, $requisition->fresh()->only(['status', 'approved_by', 'approved_at']) + ['api' => true]);
                return $requisition->fresh(['supplier', 'lines.product']);
            });
            return response()->json(['data' => $requisition, 'status' => 'approved']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $requisition = DB::transaction(function () use ($data, $id, $request): PurchaseRequisition {
                $requisition = $this->companyScope(PurchaseRequisition::query(), $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($requisition->status !== 'submitted') throw new \RuntimeException('Only submitted requisitions can be rejected.');
                app(ApprovalGuard::class)->assertDifferent($requisition);
                $before = $requisition->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
                $requisition->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $request->user()?->id, 'rejected_at' => now()]);
                app(AuditService::class)->record('purchase_requisition.rejected', $requisition, $before, $requisition->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']) + ['api' => true]);
                return $requisition->fresh();
            });
            return response()->json(['data' => $requisition, 'status' => 'rejected']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        try {
            $order = DB::transaction(function () use ($id, $request): PurchaseOrder {
                $requisition = $this->companyScope(PurchaseRequisition::with('lines'), $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($requisition->status !== 'approved') throw new \RuntimeException('Only approved requisitions can be converted.');
                if (!$requisition->suggested_supplier_id) throw new \RuntimeException('A suggested supplier is required before conversion.');
                $order = PurchaseOrder::create([
                    'company_id' => $requisition->company_id ?: $request->user()?->company_id, 'supplier_id' => $requisition->suggested_supplier_id,
                    'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), $request->user()?->company_id, $request->user()?->branch_id),
                    'date' => now()->toDateString(), 'expected_date' => $requisition->required_date, 'description' => 'Converted from requisition '.$requisition->requisition_no,
                    'status' => 'submitted', 'created_by' => $request->user()?->id,
                ]);
                foreach ($requisition->lines as $line) PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $line->product_id, 'ordered_qty' => $line->requested_qty, 'unit_price' => $line->estimated_unit_price]);
                $requisition->update(['status' => 'converted']);
                app(AuditService::class)->record('purchase_requisition.converted', $requisition, ['status' => 'approved'], ['status' => 'converted', 'purchase_order_id' => $order->id, 'api' => true]);
                return $order->fresh(['supplier', 'lines.product']);
            });
            return response()->json(['data' => $order, 'status' => 'purchase_order_created'], 201);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
