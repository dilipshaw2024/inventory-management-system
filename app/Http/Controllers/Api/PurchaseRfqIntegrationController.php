<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRfq;
use App\Models\PurchaseRfqLine;
use App\Models\PurchaseRfqSupplier;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseSupplierQuotation;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class PurchaseRfqIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,submitted,closed,cancelled,rejected'],
            'supplier_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $rfqs = $this->companyScope(PurchaseRfq::with(['requisition', 'lines.product', 'suppliers.supplier', 'suppliers.quotations']), $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['supplier_id'] ?? null, fn ($query, $supplierId) => $query->whereHas('suppliers', fn ($supplier) => $supplier->where('supplier_id', $supplierId)))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');

        return app(IntegrationCursorService::class)->paginate($rfqs, $request, 'purchasing.rfqs', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'], 'purchase_requisition_id' => ['nullable', 'integer'], 'issue_date' => ['required', 'date'],
            'response_due' => ['nullable', 'date', 'after_or_equal:issue_date'], 'description' => ['nullable', 'string', 'max:2000'],
            'supplier_ids' => ['required', 'array', 'min:1'], 'supplier_ids.*' => ['required', 'integer', 'distinct'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'integer'],
            'lines.*.requested_qty' => ['required', 'numeric', 'gt:0'], 'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(PurchaseRfq::with(['requisition', 'lines.product', 'suppliers.supplier', 'suppliers.quotations']), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $suppliers = $this->companyScope(Supplier::query(), $companyId)->whereIn('id', $data['supplier_ids'])->where('is_active', true)->get()->keyBy('id');
        if ($suppliers->count() !== count($data['supplier_ids'])) abort(422, 'One or more suppliers are not authorized or inactive.');
        $requisition = null;
        if (!empty($data['purchase_requisition_id'])) {
            $requisition = $this->companyScope(PurchaseRequisition::query(), $companyId)->findOrFail($data['purchase_requisition_id']);
            if ($requisition->status !== 'approved') abort(422, 'Only approved purchase requisitions can be linked to an RFQ.');
        }
        $products = $this->companyScope(Product::query(), $companyId)->whereIn('id', collect($data['lines'])->pluck('product_id'))->get()->keyBy('id');
        if ($products->count() !== collect($data['lines'])->pluck('product_id')->unique()->count()) abort(422, 'One or more products are not authorized for this company.');

        $rfq = DB::transaction(function () use ($data, $companyId, $request): PurchaseRfq {
            $rfq = PurchaseRfq::create([
                'company_id' => $companyId, 'purchase_requisition_id' => $data['purchase_requisition_id'] ?? null, 'external_reference' => $data['external_reference'] ?? null,
                'rfq_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_rfq', 'RFQ-'.now()->format('YmdHisv').'-'.Str::uuid()->toString(), $companyId, $request->user()?->branch_id),
                'issue_date' => $data['issue_date'], 'response_due' => $data['response_due'] ?? null,
                'description' => $data['description'] ?? null, 'status' => 'submitted', 'created_by' => $request->user()?->id,
            ]);
            foreach ($data['supplier_ids'] as $supplierId) PurchaseRfqSupplier::create(['purchase_rfq_id' => $rfq->id, 'supplier_id' => $supplierId]);
            foreach ($data['lines'] as $line) PurchaseRfqLine::create(['purchase_rfq_id' => $rfq->id, 'product_id' => $line['product_id'], 'requested_qty' => $line['requested_qty'], 'notes' => $line['notes'] ?? null]);
            app(AuditService::class)->record('purchase_rfq.created', $rfq, null, $rfq->toArray() + ['api' => true]);
            return $rfq->fresh(['requisition', 'lines.product', 'suppliers.supplier']);
        });
        return response()->json(['data' => $rfq, 'status' => 'submitted'], 201);
    }

    public function comparison(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $rfq = $this->companyScope(PurchaseRfq::with(['lines.product', 'suppliers.supplier', 'suppliers.quotations']), $companyId)->findOrFail($id);
        $rows = $rfq->suppliers->map(function (PurchaseRfqSupplier $response) use ($rfq): array {
            $quotes = $response->quotations->keyBy('purchase_rfq_line_id');
            $lines = $rfq->lines->map(function (PurchaseRfqLine $line) use ($quotes): array {
                $quote = $quotes->get($line->id);
                return [
                    'purchase_rfq_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product?->name,
                    'requested_qty' => (float) $line->requested_qty,
                    'quoted' => (bool) $quote,
                    'unit_price' => $quote ? (float) $quote->unit_price : null,
                    'extended_amount' => $quote ? round((float) $line->requested_qty * (float) $quote->unit_price, 6) : null,
                    'lead_days' => $quote?->lead_days,
                    'valid_until' => $quote?->valid_until,
                    'supplier_reference' => $quote?->supplier_reference,
                    'notes' => $quote?->notes,
                ];
            })->values();
            return [
                'rfq_supplier_id' => $response->id,
                'supplier_id' => $response->supplier_id,
                'supplier_name' => $response->supplier?->name,
                'status' => $response->status,
                'complete' => $lines->every(fn (array $line): bool => $line['quoted']),
                'quoted_line_count' => $lines->where('quoted', true)->count(),
                'line_count' => $lines->count(),
                'total_amount' => $lines->every(fn (array $line): bool => $line['quoted']) ? round((float) $lines->sum('extended_amount'), 6) : null,
                'max_lead_days' => $lines->every(fn (array $line): bool => $line['quoted']) ? (int) ($lines->max('lead_days') ?: 0) : null,
                'lines' => $lines,
            ];
        })->values();
        $completeTotals = $rows->where('complete', true)->pluck('total_amount')->filter(fn ($value): bool => $value !== null)->sort()->values();
        $ranked = $rows->map(function (array $row) use ($completeTotals): array {
            $row['rank'] = $row['complete'] && $row['total_amount'] !== null ? $completeTotals->search($row['total_amount']) + 1 : null;
            return $row;
        });
        return response()->json(['data' => [
            'rfq_id' => $rfq->id, 'rfq_no' => $rfq->rfq_no, 'status' => $rfq->status,
            'currency_code' => $rfq->currency_code ?? $request->user()?->company?->base_currency,
            'suppliers' => $ranked,
        ]]);
    }

    public function quote(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'rfq_supplier_id' => ['required', 'integer'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_rfq_line_id' => ['required', 'integer'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.lead_days' => ['nullable', 'integer', 'min:0'], 'lines.*.valid_until' => ['nullable', 'date'],
            'lines.*.supplier_reference' => ['nullable', 'string', 'max:100'], 'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $rfq = $this->companyScope(PurchaseRfq::with('lines'), $companyId)->findOrFail($id);
            if ($rfq->status !== 'submitted') throw new \RuntimeException('Only submitted RFQs can receive quotations.');
            $response = PurchaseRfqSupplier::where('purchase_rfq_id', $rfq->id)->findOrFail($data['rfq_supplier_id']);
            DB::transaction(function () use ($data, $rfq, $response, $request): void {
                $lineIds = collect($data['lines'])->pluck('purchase_rfq_line_id');
                if ($lineIds->duplicates()->isNotEmpty()) throw new \RuntimeException('A quotation line may appear only once.');
                $beforeStatus = (string) $response->status;
                $beforeLines = $response->quotations()->get()->map(fn (PurchaseSupplierQuotation $quote): array => $quote->only(['purchase_rfq_line_id', 'unit_price', 'lead_days', 'valid_until', 'supplier_reference', 'notes']))->values()->all();
                foreach ($data['lines'] as $lineData) {
                    $line = $rfq->lines->firstWhere('id', (int) $lineData['purchase_rfq_line_id']);
                    if (!$line) throw new \RuntimeException('A quotation line does not belong to the selected RFQ.');
                    PurchaseSupplierQuotation::updateOrCreate(
                        ['purchase_rfq_supplier_id' => $response->id, 'purchase_rfq_line_id' => $line->id],
                        ['unit_price' => $lineData['unit_price'], 'lead_days' => $lineData['lead_days'] ?? null, 'valid_until' => $lineData['valid_until'] ?? null, 'supplier_reference' => $lineData['supplier_reference'] ?? null, 'notes' => $lineData['notes'] ?? null]
                    );
                }
                $response->update(['status' => 'quoted', 'quoted_at' => now()]);
                $afterLines = $response->fresh('quotations')->quotations->map(fn (PurchaseSupplierQuotation $quote): array => $quote->only(['purchase_rfq_line_id', 'unit_price', 'lead_days', 'valid_until', 'supplier_reference', 'notes']))->values()->all();
                app(AuditService::class)->record('purchase_rfq.quoted', $rfq, ['status' => $beforeStatus, 'lines' => $beforeLines], ['supplier_id' => $response->supplier_id, 'status' => 'quoted', 'lines' => $afterLines, 'api' => true, 'actor_id' => $request->user()?->id]);
            });
            return response()->json(['data' => $response->fresh('quotations'), 'status' => 'quoted']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function award(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate(['rfq_supplier_id' => ['required', 'integer']]);
        try {
            $order = DB::transaction(function () use ($data, $id, $companyId, $request): PurchaseOrder {
                $rfq = $this->companyScope(PurchaseRfq::with('lines'), $companyId)->lockForUpdate()->findOrFail($id);
                if ($rfq->status !== 'submitted') throw new \RuntimeException('Only submitted RFQs can be awarded.');
                $response = PurchaseRfqSupplier::with('quotations')->where('purchase_rfq_id', $rfq->id)->findOrFail($data['rfq_supplier_id']);
                $quotes = $response->quotations->keyBy('purchase_rfq_line_id');
                if ($rfq->lines->contains(fn (PurchaseRfqLine $line): bool => !$quotes->has($line->id))) throw new \RuntimeException('The selected supplier must quote every RFQ line before award.');
                $leadDays = (int) ($response->quotations->max('lead_days') ?: 0);
                $order = PurchaseOrder::create([
                    'company_id' => $companyId, 'supplier_id' => $response->supplier_id,
                    'po_no' => app(NumberingSequenceService::class)->nextOrFallback('purchase_order', 'PO-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                    'date' => now()->toDateString(), 'expected_date' => now()->addDays($leadDays)->toDateString(),
                    'description' => 'Awarded from RFQ '.$rfq->rfq_no, 'status' => 'submitted', 'created_by' => $request->user()?->id,
                ]);
                foreach ($rfq->lines as $line) PurchaseOrderLine::create(['purchase_order_id' => $order->id, 'product_id' => $line->product_id, 'ordered_qty' => $line->requested_qty, 'unit_price' => $quotes[$line->id]->unit_price]);
                $rfq->update(['status' => 'closed']);
                app(AuditService::class)->record('purchase_rfq.awarded', $rfq, ['status' => 'submitted'], ['status' => 'closed', 'purchase_order_id' => $order->id, 'supplier_id' => $response->supplier_id, 'api' => true]);
                return $order->fresh(['supplier', 'lines.product']);
            });
            return response()->json(['data' => $order, 'status' => 'purchase_order_created'], 201);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function close(int $id): JsonResponse
    {
        $companyId = auth()->user()?->company_id;
        try {
            $rfq = DB::transaction(function () use ($id, $companyId): PurchaseRfq {
                $rfq = $this->companyScope(PurchaseRfq::query(), $companyId)->lockForUpdate()->findOrFail($id);
                if ($rfq->status !== 'submitted') throw new \RuntimeException('Only submitted RFQs can be closed.');
                $before = $rfq->only(['status']);
                $rfq->update(['status' => 'closed']);
                app(AuditService::class)->record('purchase_rfq.closed', $rfq, $before, $rfq->fresh()->only(['status']));
                return $rfq->fresh();
            });
            return response()->json(['data' => $rfq, 'status' => 'closed']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        $companyId = $request->user()?->company_id;
        try {
            $rfq = DB::transaction(function () use ($data, $id, $companyId, $request): PurchaseRfq {
                $rfq = $this->companyScope(PurchaseRfq::query(), $companyId)->lockForUpdate()->findOrFail($id);
                if ($rfq->status !== 'submitted') throw new \RuntimeException('Only submitted RFQs can be rejected.');
                app(\App\Services\ApprovalGuard::class)->assertDifferent($rfq);
                $before = $rfq->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
                $rfq->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $request->user()?->id, 'rejected_at' => now()]);
                app(AuditService::class)->record('purchase_rfq.rejected', $rfq, $before, $rfq->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']) + ['api' => true]);
                return $rfq->fresh();
            });
            return response()->json(['data' => $rfq, 'status' => 'rejected']);
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
