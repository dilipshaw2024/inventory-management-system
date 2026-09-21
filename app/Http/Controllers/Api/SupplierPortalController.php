<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseRfq;
use App\Models\PurchaseRfqSupplier;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierPortalController extends Controller
{
    public function issueInvitation(Request $request, int $id, int $supplierId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $rfq = PurchaseRfq::where('company_id', $companyId)->findOrFail($id);
        if ($rfq->status !== 'submitted') return response()->json(['message' => 'Only submitted RFQs can receive supplier invitations.'], 422);
        if ($rfq->response_due && $rfq->response_due->isPast()) return response()->json(['message' => 'The RFQ response deadline has passed.'], 422);
        $data = $request->validate(['expires_at' => ['nullable', 'date', 'after:now']]);
        $response = PurchaseRfqSupplier::where('purchase_rfq_id', $rfq->id)->where('supplier_id', $supplierId)->firstOrFail();
        $token = Str::random(64);
        $expiresAt = $data['expires_at'] ?? ($rfq->response_due?->isFuture() ? $rfq->response_due->endOfDay() : now()->addDays(7));
        $response->update(['portal_token_hash' => hash('sha256', $token), 'portal_token_expires_at' => $expiresAt, 'portal_last_accessed_at' => null]);
        app(AuditService::class)->record('purchase_rfq.supplier_portal_invited', $rfq, null, ['rfq_supplier_id' => $response->id, 'supplier_id' => $supplierId, 'expires_at' => $expiresAt, 'api' => true]);
        return response()->json(['data' => ['rfq_supplier_id' => $response->id, 'supplier_id' => $supplierId, 'expires_at' => $expiresAt, 'token' => $token]]);
    }

    public function revokeInvitation(Request $request, int $id, int $supplierId): JsonResponse
    {
        $rfq = PurchaseRfq::where('company_id', $request->user()?->company_id)->findOrFail($id);
        $response = PurchaseRfqSupplier::where('purchase_rfq_id', $rfq->id)->where('supplier_id', $supplierId)->firstOrFail();
        $response->update(['portal_token_hash' => null, 'portal_token_expires_at' => null]);
        app(AuditService::class)->record('purchase_rfq.supplier_portal_revoked', $rfq, null, ['rfq_supplier_id' => $response->id, 'supplier_id' => $supplierId, 'api' => true]);
        return response()->json(['status' => 'revoked']);
    }

    public function show(string $token): JsonResponse
    {
        $response = $this->response($token);
        $response->update(['portal_last_accessed_at' => now()]);
        return response()->json(['data' => $this->payload($response)]);
    }

    public function quote(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_rfq_line_id' => ['required', 'integer'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.lead_days' => ['nullable', 'integer', 'min:0'],
            'lines.*.valid_until' => ['nullable', 'date'],
            'lines.*.supplier_reference' => ['nullable', 'string', 'max:100'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $result = DB::transaction(function () use ($data, $token): PurchaseRfqSupplier {
                $response = $this->response($token, true);
                if ($response->status === 'declined') throw new \RuntimeException('This supplier invitation has already been declined.');
                $rfq = $response->rfq()->with('lines')->lockForUpdate()->firstOrFail();
                $lineIds = collect($data['lines'])->pluck('purchase_rfq_line_id');
                if ($lineIds->duplicates()->isNotEmpty()) throw new \RuntimeException('A quotation line may appear only once.');
                foreach ($data['lines'] as $lineData) {
                    $line = $rfq->lines->firstWhere('id', (int) $lineData['purchase_rfq_line_id']);
                    if (!$line) throw new \RuntimeException('A quotation line does not belong to this RFQ.');
                    $response->quotations()->updateOrCreate(
                        ['purchase_rfq_line_id' => $line->id],
                        ['unit_price' => $lineData['unit_price'], 'lead_days' => $lineData['lead_days'] ?? null, 'valid_until' => $lineData['valid_until'] ?? null, 'supplier_reference' => $lineData['supplier_reference'] ?? null, 'notes' => $lineData['notes'] ?? null]
                    );
                }
                $response->update(['status' => 'quoted', 'quoted_at' => now(), 'portal_last_accessed_at' => now()]);
                app(AuditService::class)->record('purchase_rfq.supplier_portal_quoted', $rfq, null, ['rfq_supplier_id' => $response->id, 'supplier_id' => $response->supplier_id, 'api' => true]);
                return $response->fresh('quotations');
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $result, 'status' => 'quoted']);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            $response = DB::transaction(function () use ($data, $token): PurchaseRfqSupplier {
                $response = $this->response($token, true);
                if ($response->status === 'quoted') throw new \RuntimeException('A quoted supplier response cannot be declined.');
                $rfq = $response->rfq()->lockForUpdate()->firstOrFail();
                $response->update(['status' => 'declined', 'notes' => $data['reason'], 'portal_last_accessed_at' => now()]);
                app(AuditService::class)->record('purchase_rfq.supplier_portal_declined', $rfq, ['status' => 'invited'], ['status' => 'declined', 'rfq_supplier_id' => $response->id, 'supplier_id' => $response->supplier_id, 'reason' => $data['reason'], 'api' => true]);
                return $response->fresh(['supplier', 'quotations']);
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $response, 'status' => 'declined']);
    }

    private function response(string $token, bool $forUpdate = false): PurchaseRfqSupplier
    {
        $query = PurchaseRfqSupplier::with(['supplier', 'rfq.lines.product', 'quotations']);
        if ($forUpdate) $query->lockForUpdate();
        $response = $query->where('portal_token_hash', hash('sha256', $token))->first();
        if (!$response || ($response->portal_token_expires_at && $response->portal_token_expires_at->isPast())) abort(404, 'This supplier invitation is invalid or expired.');
        if ($response->rfq->status !== 'submitted') abort(410, 'This RFQ is no longer accepting supplier quotations.');
        if ($response->rfq->response_due && $response->rfq->response_due->isPast()) abort(410, 'The RFQ response deadline has passed.');
        return $response;
    }

    private function payload(PurchaseRfqSupplier $response): array
    {
        return [
            'rfq_supplier_id' => $response->id, 'supplier' => $response->supplier,
            'rfq' => $response->rfq->only(['id', 'rfq_no', 'issue_date', 'response_due', 'description']),
            'lines' => $response->rfq->lines->map(fn ($line): array => ['id' => $line->id, 'product' => $line->product, 'requested_qty' => $line->requested_qty, 'notes' => $line->notes, 'quotation' => $response->quotations->firstWhere('purchase_rfq_line_id', $line->id)])->values()->all(),
        ];
    }
}
