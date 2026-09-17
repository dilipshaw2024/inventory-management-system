<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\InventoryReturn;
use App\Models\Supplier;
use App\Models\SupplierClaim;
use App\Models\PurchaseInvoice;
use App\Services\AuditService;
use App\Services\IntegrationCursorService;
use App\Services\NumberingSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierClaimIntegrationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'status' => ['nullable', 'in:open,submitted,accepted,rejected,partially_settled,settled'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $claims = SupplierClaim::with(['supplier', 'purchaseInvoice', 'goodsReceipt', 'inventoryReturn'])
            ->where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['supplier_id'] ?? null, fn ($query, $supplierId) => $query->where('supplier_id', $supplierId))
            ->when($data['updated_since'] ?? null, fn ($query, $date) => $query->where('updated_at', '>=', $date))
            ->orderBy('updated_at')->orderBy('id');
        return app(IntegrationCursorService::class)->paginate($claims, $request, 'purchasing.supplier-claims', (int) ($data['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'claim_no' => ['nullable', 'string', 'max:80', Rule::unique('supplier_claims', 'claim_no')->where(fn ($query) => $query->where('company_id', $companyId))],
            'external_reference' => ['nullable', 'string', 'max:150', Rule::unique('supplier_claims', 'external_reference')->where(fn ($query) => $query->where('company_id', $companyId))],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
            'purchase_invoice_id' => ['nullable', 'integer', Rule::exists('purchase_invoices', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'goods_receipt_id' => ['nullable', 'integer', Rule::exists('goods_receipts', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'inventory_return_id' => ['nullable', 'integer', Rule::exists('inventory_returns', 'id')->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))],
            'claim_date' => ['required', 'date'], 'reason_code' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:3000'], 'claim_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (empty($data['goods_receipt_id']) && empty($data['inventory_return_id'])) abort(422, 'A goods receipt or purchase return is required for a supplier claim.');
        if (!empty($data['goods_receipt_id']) && !empty($data['inventory_return_id'])) abort(422, 'A supplier claim cannot reference both a goods receipt and a purchase return.');
        if (!empty($data['external_reference'])) {
            $existing = SupplierClaim::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return response()->json(['data' => $existing->load(['supplier', 'goodsReceipt', 'inventoryReturn']), 'status' => 'duplicate_ignored']);
        }
        $supplier = Supplier::where('company_id', $companyId)->findOrFail($data['supplier_id']);
        if (!empty($data['purchase_invoice_id'])) {
            $invoice = PurchaseInvoice::where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->findOrFail($data['purchase_invoice_id']);
            if ((int) $invoice->supplier_id !== (int) $supplier->id) abort(422, 'Claim invoice does not match the selected supplier.');
        }
        if (!empty($data['goods_receipt_id'])) {
            $receipt = GoodsReceipt::where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->with('purchaseOrder')->findOrFail($data['goods_receipt_id']);
            if ((int) $receipt->purchaseOrder?->supplier_id !== (int) $supplier->id) abort(422, 'Claim supplier does not match the goods receipt supplier.');
        }
        if (!empty($data['inventory_return_id'])) {
            $return = InventoryReturn::where(function ($query) use ($companyId): void { $query->where('company_id', $companyId)->orWhereNull('company_id'); })->findOrFail($data['inventory_return_id']);
            if ($return->return_type !== 'purchase' || (int) $return->supplier_id !== (int) $supplier->id) abort(422, 'Claim must reference a purchase return for the selected supplier.');
        }
        $claim = DB::transaction(function () use ($data, $companyId, $request): SupplierClaim {
            $claim = SupplierClaim::create([
                'company_id' => $companyId, 'supplier_id' => $data['supplier_id'], 'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null, 'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'inventory_return_id' => $data['inventory_return_id'] ?? null, 'claim_no' => $data['claim_no'] ?? app(NumberingSequenceService::class)->nextOrFallback('supplier_claim', 'SC-'.now()->format('YmdHis').'-'.random_int(100, 999), $companyId, $request->user()?->branch_id),
                'external_reference' => $data['external_reference'] ?? null, 'claim_date' => $data['claim_date'], 'reason_code' => $data['reason_code'],
                'description' => $data['description'] ?? null, 'claim_amount' => $data['claim_amount'] ?? 0, 'created_by' => $request->user()?->id,
            ]);
            app(AuditService::class)->record('supplier_claim.created', $claim, null, $claim->toArray() + ['api' => true]);
            return $claim;
        });
        return response()->json(['data' => $claim->load(['supplier', 'purchaseInvoice', 'goodsReceipt', 'inventoryReturn']), 'status' => 'open'], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:submitted,accepted,rejected,partially_settled,settled'], 'resolution_notes' => ['nullable', 'string', 'max:3000'], 'settled_amount' => ['nullable', 'numeric', 'gt:0'], 'settlement_reference' => ['nullable', 'string', 'max:150']]);
        if (in_array($data['status'], ['rejected', 'accepted', 'settled'], true) && empty($data['resolution_notes'])) abort(422, 'Resolution notes are required for this claim status.');
        if (in_array($data['status'], ['partially_settled', 'settled'], true) && (empty($data['settled_amount']) || empty($data['settlement_reference']))) abort(422, 'Settlement amount and reference are required for settlement.');
        $claim = DB::transaction(function () use ($data, $id, $request): SupplierClaim {
            $claim = SupplierClaim::where(function ($query) use ($request): void { $query->where('company_id', $request->user()?->company_id)->orWhereNull('company_id'); })->lockForUpdate()->findOrFail($id);
            if (in_array($claim->status, ['rejected', 'settled'], true)) throw new \RuntimeException('Closed supplier claims cannot be changed.');
            $allowed = ['open' => ['submitted'], 'submitted' => ['accepted', 'rejected'], 'accepted' => ['partially_settled', 'settled'], 'partially_settled' => ['settled']];
            if (!in_array($data['status'], $allowed[$claim->status] ?? [], true)) throw new \RuntimeException('Invalid supplier claim status transition.');
            if (in_array($data['status'], ['accepted', 'rejected', 'partially_settled', 'settled'], true)) app(\App\Services\ApprovalGuard::class)->assertDifferent($claim);
            $settledAmount = array_key_exists('settled_amount', $data) ? (float) $data['settled_amount'] : (float) $claim->settled_amount;
            if (in_array($data['status'], ['partially_settled', 'settled'], true)) {
                if ($settledAmount > (float) $claim->claim_amount + 0.000001) throw new \RuntimeException('Settlement exceeds the supplier claim amount.');
                if ($data['status'] === 'partially_settled' && $settledAmount + 0.000001 >= (float) $claim->claim_amount) throw new \RuntimeException('A full settlement must use the settled status.');
                if ($data['status'] === 'settled' && $settledAmount + 0.000001 < (float) $claim->claim_amount) throw new \RuntimeException('The settled amount must cover the full supplier claim.');
            }
            $before = $claim->only(['status', 'resolution_notes', 'resolved_by', 'resolved_at', 'settled_amount', 'settlement_reference', 'settled_by', 'settled_at', 'settlement_posted_amount', 'settlement_journal_id']);
            $updates = ['status' => $data['status'], 'resolution_notes' => $data['resolution_notes'] ?? null];
            if (in_array($data['status'], ['accepted', 'rejected', 'settled'], true)) $updates += ['resolved_by' => $request->user()?->id, 'resolved_at' => now()];
            if (in_array($data['status'], ['partially_settled', 'settled'], true)) $updates += ['settled_amount' => $settledAmount, 'settlement_reference' => $data['settlement_reference'], 'settled_by' => $request->user()?->id, 'settled_at' => now()];
            if (in_array($data['status'], ['partially_settled', 'settled'], true)) {
                $increment = $settledAmount - (float) $claim->settled_amount;
                if ($increment <= 0.000001) throw new \RuntimeException('Settlement amount must increase the previous settlement.');
                $journal = app(\App\Services\AutomaticAccountingService::class)->postSupplierClaimSettlement($claim, $increment);
                if ($journal) $updates += ['settlement_posted_amount' => (float) $claim->settlement_posted_amount + $increment, 'settlement_journal_id' => $journal->id];
            }
            $claim->update($updates);
            app(AuditService::class)->record('supplier_claim.status_changed', $claim, $before, $claim->fresh()->only(array_keys($before)) + ['api' => true]);
            return $claim->fresh();
        });
        return response()->json(['data' => $claim->load(['supplier', 'purchaseInvoice', 'goodsReceipt', 'inventoryReturn']), 'status' => $claim->status]);
    }
}
