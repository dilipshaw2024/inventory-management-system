<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesQuotation;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerPortalController extends Controller
{
    public function issueInvitation(Request $request, int $id): JsonResponse
    {
        $quotation = SalesQuotation::where('company_id', $request->user()?->company_id)->findOrFail($id);
        if ($quotation->status !== 'submitted') return response()->json(['message' => 'Only submitted quotations can receive customer invitations.'], 422);
        if ($quotation->valid_until && $quotation->valid_until->isPast()) return response()->json(['message' => 'This quotation has expired.'], 422);
        $data = $request->validate(['expires_at' => ['nullable', 'date', 'after:now']]);
        $token = Str::random(64);
        $expiresAt = $data['expires_at'] ?? ($quotation->valid_until?->isFuture() ? $quotation->valid_until->endOfDay() : now()->addDays(7));
        $quotation->update(['customer_portal_token_hash' => hash('sha256', $token), 'customer_portal_token_expires_at' => $expiresAt, 'customer_portal_last_accessed_at' => null]);
        app(AuditService::class)->record('sales_quotation.customer_portal_invited', $quotation, null, ['expires_at' => $expiresAt, 'customer_id' => $quotation->customer_id, 'api' => true]);
        return response()->json(['data' => ['quotation_id' => $quotation->id, 'customer_id' => $quotation->customer_id, 'expires_at' => $expiresAt, 'token' => $token]]);
    }

    public function revokeInvitation(Request $request, int $id): JsonResponse
    {
        $quotation = SalesQuotation::where('company_id', $request->user()?->company_id)->findOrFail($id);
        $quotation->update(['customer_portal_token_hash' => null, 'customer_portal_token_expires_at' => null]);
        app(AuditService::class)->record('sales_quotation.customer_portal_revoked', $quotation, null, ['customer_id' => $quotation->customer_id, 'api' => true]);
        return response()->json(['status' => 'revoked']);
    }

    public function show(string $token): JsonResponse
    {
        $quotation = $this->quotation($token);
        $quotation->update(['customer_portal_last_accessed_at' => now()]);
        return response()->json(['data' => $this->payload($quotation)]);
    }

    public function accept(string $token): JsonResponse
    {
        return $this->respond($token, 'accepted', null);
    }

    public function decline(Request $request, string $token): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return $this->respond($token, 'declined', $data['reason']);
    }

    private function respond(string $token, string $decision, ?string $notes): JsonResponse
    {
        try {
            $quotation = DB::transaction(function () use ($token, $decision, $notes): SalesQuotation {
                $quotation = $this->quotation($token, true);
                if ($quotation->customer_response_status !== 'pending' && $quotation->customer_response_status !== $decision) throw new \RuntimeException('This quotation already has a different customer response.');
                $before = $quotation->only(['customer_response_status', 'customer_responded_at', 'customer_response_notes']);
                $quotation->update(['customer_response_status' => $decision, 'customer_responded_at' => now(), 'customer_response_notes' => $notes, 'customer_portal_last_accessed_at' => now()]);
                app(AuditService::class)->record('sales_quotation.customer_'.$decision, $quotation, $before, $quotation->fresh()->only(['customer_response_status', 'customer_responded_at', 'customer_response_notes']));
                return $quotation->fresh(['customer', 'lines.product']);
            });
        } catch (\RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        return response()->json(['data' => $quotation, 'status' => $decision]);
    }

    private function quotation(string $token, bool $forUpdate = false): SalesQuotation
    {
        $query = SalesQuotation::with(['customer', 'lines.product'])->where('customer_portal_token_hash', hash('sha256', $token));
        if ($forUpdate) $query->lockForUpdate();
        $quotation = $query->first();
        if (!$quotation || ($quotation->customer_portal_token_expires_at && $quotation->customer_portal_token_expires_at->isPast())) abort(404, 'This customer quotation invitation is invalid or expired.');
        if ($quotation->status !== 'submitted') abort(410, 'This quotation is no longer accepting customer responses.');
        if ($quotation->valid_until && $quotation->valid_until->isPast()) abort(410, 'This quotation has expired.');
        return $quotation;
    }

    private function payload(SalesQuotation $quotation): array
    {
        return ['quotation' => $quotation->only(['id', 'quote_no', 'quote_date', 'valid_until', 'description', 'customer_response_status', 'customer_response_notes']), 'customer' => $quotation->customer, 'lines' => $quotation->lines->map(fn ($line): array => ['id' => $line->id, 'product' => $line->product, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount])->values()->all()];
    }
}
