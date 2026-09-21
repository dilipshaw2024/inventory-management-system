<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationLine;
use App\Services\ApprovalGuard;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use App\Services\SalesDiscountPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesQuotationIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:draft,submitted,approved,rejected,expired,converted'],
            'customer_id' => ['nullable', 'integer'], 'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $quotations = $this->companyScope(SalesQuotation::with(['customer', 'lines.product']), $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($quotations, $request, 'sales.quotations', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:150'], 'customer_id' => ['required', 'integer'],
            'quote_date' => ['required', 'date'], 'valid_until' => ['nullable', 'date', 'after_or_equal:quote_date'],
            'description' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'], 'lines.*.discount_amount' => ['required', 'numeric', 'min:0'],
        ]);
        if (!empty($data['external_reference'])) {
            $existing = $this->companyScope(SalesQuotation::with(['customer', 'lines.product']), $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing, 'status' => 'duplicate_ignored']);
        }
        $customer = $this->companyScope(Customer::query(), $companyId)->whereKey($data['customer_id'])->where('is_active', true)->first();
        if (!$customer) abort(422, 'Customer is not authorized or inactive.');
        $productIds = collect($data['lines'])->pluck('product_id');
        if ($this->companyScope(Product::query(), $companyId)->whereIn('id', $productIds)->count() !== $productIds->unique()->count()) abort(422, 'One or more products are not authorized for this company.');
        $quotation = DB::transaction(function () use ($data, $companyId, $request): SalesQuotation {
            $quotation = SalesQuotation::create([
                'company_id' => $companyId, 'external_reference' => $data['external_reference'] ?? null,
                'quote_no' => app(NumberingSequenceService::class)->nextOrFallback('sales_quotation', 'QT-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'customer_id' => $data['customer_id'], 'quote_date' => $data['quote_date'], 'valid_until' => $data['valid_until'] ?? null,
                'description' => $data['description'] ?? null, 'created_by' => $request->user()?->id, 'status' => 'submitted',
            ]);
            foreach ($data['lines'] as $line) SalesQuotationLine::create(['sales_quotation_id' => $quotation->id, 'product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount_amount' => $line['discount_amount']]);
            app(AuditService::class)->record('sales_quotation.created', $quotation, null, $quotation->toArray() + ['api' => true]);
            return $quotation->fresh(['customer', 'lines.product']);
        });
        return response()->json(['data' => $quotation, 'status' => 'submitted'], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        try {
            $quotation = DB::transaction(function () use ($id, $request): SalesQuotation {
                $quotation = $this->companyScope(SalesQuotation::with('lines'), $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($quotation->status !== 'submitted') throw new \RuntimeException('Only submitted quotations can be approved.');
                if ($quotation->customer_response_status === 'declined') throw new \RuntimeException('A quotation declined by the customer cannot be approved.');
                if ($quotation->valid_until && $quotation->valid_until->isPast()) throw new \RuntimeException('This quotation has expired.');
                app(ApprovalGuard::class)->assertDifferent($quotation);
                app(SalesDiscountPolicyService::class)->assertQuotationCanApprove($quotation);
                $before = $quotation->only(['status', 'approved_by', 'approved_at']);
                $quotation->update(['status' => 'approved', 'approved_by' => $request->user()?->id, 'approved_at' => now()]);
                app(AuditService::class)->record('sales_quotation.approved', $quotation, $before, $quotation->fresh()->only(['status', 'approved_by', 'approved_at']) + ['api' => true]);
                return $quotation->fresh(['customer', 'lines.product']);
            });
            return response()->json(['data' => $quotation, 'status' => 'approved']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'max:2000']]);
        try {
            $quotation = DB::transaction(function () use ($data, $id, $request): SalesQuotation {
                $quotation = $this->companyScope(SalesQuotation::query(), $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($quotation->status !== 'submitted') throw new \RuntimeException('Only submitted quotations can be rejected.');
                app(ApprovalGuard::class)->assertDifferent($quotation);
                $before = $quotation->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']);
                $quotation->update(['status' => 'rejected', 'rejection_reason' => $data['rejection_reason'], 'rejected_by' => $request->user()?->id, 'rejected_at' => now()]);
                app(AuditService::class)->record('sales_quotation.rejected', $quotation, $before, $quotation->fresh()->only(['status', 'rejection_reason', 'rejected_by', 'rejected_at']) + ['api' => true]);
                return $quotation->fresh();
            });
            return response()->json(['data' => $quotation, 'status' => 'rejected']);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    public function convert(Request $request, int $id): JsonResponse
    {
        try {
            $order = DB::transaction(function () use ($id, $request): SalesOrder {
                $quotation = $this->companyScope(SalesQuotation::with('lines'), $request->user()?->company_id)->lockForUpdate()->findOrFail($id);
                if ($quotation->status !== 'approved') throw new \RuntimeException('Only approved quotations can be converted.');
                if ($quotation->valid_until && $quotation->valid_until->isPast()) throw new \RuntimeException('This quotation has expired.');
                $order = SalesOrder::create([
                    'company_id' => $quotation->company_id ?: $request->user()?->company_id, 'customer_id' => $quotation->customer_id,
                    'order_no' => app(NumberingSequenceService::class)->nextOrFallback('sales_order', 'SO-'.now()->format('YmdHis').'-'.random_int(100, 999), $request->user()?->company_id, $request->user()?->branch_id),
                    'date' => now()->toDateString(), 'requested_date' => $quotation->valid_until, 'description' => 'Converted from quotation '.$quotation->quote_no,
                    'status' => 'submitted', 'created_by' => $request->user()?->id,
                ]);
                foreach ($quotation->lines as $line) SalesOrderLine::create(['sales_order_id' => $order->id, 'product_id' => $line->product_id, 'ordered_qty' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount]);
                $quotation->update(['status' => 'converted']);
                app(AuditService::class)->record('sales_quotation.converted', $quotation, ['status' => 'approved'], ['status' => 'converted', 'sales_order_id' => $order->id, 'api' => true]);
                return $order->fresh(['customer', 'lines.product']);
            });
            return response()->json(['data' => $order, 'status' => 'sales_order_created'], 201);
        } catch (\RuntimeException $exception) { return response()->json(['message' => $exception->getMessage()], 422); }
    }

    private function companyScope($query, ?int $companyId)
    {
        return $query->where(fn ($nested) => $nested->where('company_id', $companyId)->orWhereNull('company_id'));
    }
}
