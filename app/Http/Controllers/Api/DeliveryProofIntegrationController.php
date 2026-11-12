<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\DocumentAttachment;
use App\Services\AuditService;
use App\Services\WarehouseFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DeliveryProofIntegrationController extends Controller
{
    public function index(Request $request, int $deliveryId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $delivery = Delivery::where('company_id', $companyId)->findOrFail($deliveryId);
        return response()->json(['data' => DeliveryProof::with('attachment')->where('company_id', $companyId)->where('delivery_id', $delivery->id)->orderByDesc('delivered_at')->orderByDesc('id')->get()]);
    }

    public function store(Request $request, int $deliveryId): JsonResponse
    {
        $companyId = $request->user()?->company_id;
        $data = $request->validate([
            'proof_type' => ['required', Rule::in(['recipient', 'signature', 'photo', 'otp', 'other'])],
            'external_reference' => ['required', 'string', 'max:150'],
            'recipient_name' => ['nullable', 'string', 'max:150'],
            'recipient_contact' => ['nullable', 'string', 'max:100'],
            'delivered_at' => ['nullable', 'date'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'attachment_id' => ['nullable', 'integer', Rule::exists('document_attachments', 'id')->where(fn ($query) => $query->where('company_id', $companyId))],
        ]);

        $proof = DB::transaction(function () use ($request, $companyId, $deliveryId, $data): DeliveryProof {
            $existing = DeliveryProof::where('company_id', $companyId)->where('external_reference', $data['external_reference'])->first();
            if ($existing) return $existing->load('attachment');
            $delivery = Delivery::where('company_id', $companyId)->with('operations')->lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->status !== 'approved' || $delivery->fulfillment_status === 'cancelled' || !$delivery->operations->firstWhere('operation_type', 'dispatch')) {
                throw new \RuntimeException('Only dispatched deliveries can receive proof of delivery.');
            }
            if (!empty($data['attachment_id'])) {
                $attachment = DocumentAttachment::where('company_id', $companyId)->findOrFail($data['attachment_id']);
                if ($attachment->attachable_type !== $delivery->getMorphClass() || (int) $attachment->attachable_id !== (int) $delivery->id) {
                    throw new \RuntimeException('The proof attachment must belong to this delivery.');
                }
            }
            $deliveredAt = $data['delivered_at'] ?? now();
            $proof = DeliveryProof::create($data + ['company_id' => $companyId, 'delivery_id' => $delivery->id, 'delivered_at' => $deliveredAt, 'captured_by' => $request->user()?->id]);
            $before = $delivery->only(['fulfillment_status', 'delivered_at', 'proof_of_delivery']);
            $delivery->update(['fulfillment_status' => 'delivered', 'delivered_at' => $deliveredAt, 'proof_of_delivery' => $data['external_reference']]);
            app(WarehouseFulfillmentService::class)->markDelivered($delivery, $deliveredAt);
            app(AuditService::class)->record('delivery.proof_of_delivery.created', $proof, null, $proof->toArray());
            app(AuditService::class)->record('delivery.delivered', $delivery, $before, $delivery->fresh()->only(['fulfillment_status', 'delivered_at', 'proof_of_delivery']));
            return $proof->load('attachment');
        });

        return response()->json(['data' => $proof, 'status' => $proof->wasRecentlyCreated ? 'created' : 'duplicate_ignored', 'idempotent' => !$proof->wasRecentlyCreated], $proof->wasRecentlyCreated ? 201 : 200);
    }
}
