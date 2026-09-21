<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationWebhookSubscription;
use App\Models\IntegrationWebhookDelivery;
use App\Services\AuditService;
use Illuminate\Http\Request;

class WebhookSubscriptionController extends Controller
{
    public function index()
    {
        return response()->json(['data' => IntegrationWebhookSubscription::where('company_id', $this->companyId())->latest()->paginate(100)]);
    }

    public function store(Request $request)
    {
        $companyId = auth()->user()?->company_id;
        if (!$companyId) return response()->json(['message' => 'Select a company before creating a webhook subscription.'], 422);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'endpoint_url' => ['required', 'url', 'regex:/^https?:\\/\\//i', 'max:2048'], 'secret' => ['nullable', 'string', 'min:16', 'max:255'], 'event_types' => ['required', 'array', 'min:1'], 'event_types.*' => ['required', 'string', 'max:150']]);
        $secret = $data['secret'] ?? bin2hex(random_bytes(32));
        $subscription = IntegrationWebhookSubscription::create(['company_id' => $companyId, 'name' => $data['name'], 'endpoint_url' => $data['endpoint_url'], 'secret' => $secret, 'event_types' => array_values(array_unique($data['event_types'])), 'is_active' => true]);
        app(AuditService::class)->record('integration_webhook.created', $subscription, null, $subscription->toArray());
        return response()->json(['data' => $subscription, 'secret' => $secret], 201);
    }

    public function deliveries(Request $request)
    {
        $companyId = $this->companyId();
        $data = $request->validate(['status' => ['nullable', 'in:pending,failed,dead_letter,sent,cancelled'], 'event_type' => ['nullable', 'string', 'max:150'], 'event_id' => ['nullable', 'string', 'max:191'], 'subscription_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $deliveries = IntegrationWebhookDelivery::with('subscription:id,name')
            ->where('company_id', $companyId)
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['event_type'] ?? null, fn ($query, $event) => $query->where('event_type', $event))
            ->when($data['event_id'] ?? null, fn ($query, $eventId) => $query->where('event_id', $eventId))
            ->when($data['subscription_id'] ?? null, fn ($query, $id) => $query->where('subscription_id', $id))
            ->latest('id')->paginate((int) ($data['per_page'] ?? 50))->withQueryString();
        return response()->json($deliveries);
    }

    public function retry(int $id)
    {
        $delivery = IntegrationWebhookDelivery::with('subscription')->where('company_id', $this->companyId())->findOrFail($id);
        if (!in_array($delivery->status, ['failed', 'dead_letter'], true)) return response()->json(['message' => 'Only failed or dead-letter webhook deliveries can be retried manually.'], 422);
        if (!$delivery->subscription?->is_active) return response()->json(['message' => 'The webhook subscription is inactive.'], 422);
        $before = ['status' => $delivery->status, 'attempts' => (int) $delivery->attempts, 'dead_lettered_at' => optional($delivery->dead_lettered_at)->toISOString()];
        $delivery->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'dead_lettered_at' => null, 'last_error' => null, 'response_code' => null]);
        app(AuditService::class)->record('integration_webhook.requeued', $delivery, $before, ['status' => 'pending', 'attempts' => 0, 'dead_lettered_at' => null]);
        return response()->json(['data' => $delivery->fresh('subscription:id,name'), 'status' => 'pending']);
    }

    public function deactivate(int $id)
    {
        $companyId = $this->companyId();
        $subscription = IntegrationWebhookSubscription::where('company_id', $companyId)->findOrFail($id);
        $cancelled = IntegrationWebhookDelivery::where('company_id', $companyId)->where('subscription_id', $subscription->id)->whereIn('status', ['pending', 'failed'])->update(['status' => 'cancelled', 'next_attempt_at' => null]);
        $subscription->update(['is_active' => false]);
        app(AuditService::class)->record('integration_webhook.deactivated', $subscription, ['is_active' => true], ['is_active' => false, 'cancelled_deliveries' => $cancelled]);
        return response()->json(['data' => $subscription, 'status' => 'inactive', 'cancelled_deliveries' => $cancelled]);
    }

    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'endpoint_url' => ['sometimes', 'required', 'url', 'regex:/^https?:\/\//i', 'max:2048'],
            'event_types' => ['sometimes', 'required', 'array', 'min:1'],
            'event_types.*' => ['required', 'string', 'max:150'],
        ]);
        if ($data === []) return response()->json(['message' => 'At least one subscription field is required.'], 422);
        $subscription = IntegrationWebhookSubscription::where('company_id', $this->companyId())->findOrFail($id);
        $before = $subscription->only(['name', 'endpoint_url', 'event_types', 'is_active']);
        if (array_key_exists('event_types', $data)) $data['event_types'] = array_values(array_unique($data['event_types']));
        $subscription->update($data);
        app(AuditService::class)->record('integration_webhook.updated', $subscription, $before, $subscription->fresh()->only(['name', 'endpoint_url', 'event_types', 'is_active']));
        return response()->json(['data' => $subscription->fresh(), 'status' => 'updated']);
    }

    public function activate(int $id)
    {
        $subscription = IntegrationWebhookSubscription::where('company_id', $this->companyId())->findOrFail($id);
        if ($subscription->is_active) return response()->json(['data' => $subscription, 'status' => 'active']);
        $subscription->update(['is_active' => true]);
        app(AuditService::class)->record('integration_webhook.activated', $subscription, ['is_active' => false], ['is_active' => true]);
        return response()->json(['data' => $subscription, 'status' => 'active']);
    }

    public function rotateSecret(Request $request, int $id)
    {
        $data = $request->validate(['secret' => ['nullable', 'string', 'min:16', 'max:255']]);
        $subscription = IntegrationWebhookSubscription::where('company_id', $this->companyId())->findOrFail($id);
        $secret = $data['secret'] ?? bin2hex(random_bytes(32));
        $subscription->update(['secret' => $secret]);
        app(AuditService::class)->record('integration_webhook.secret_rotated', $subscription, ['secret_rotated' => true], ['secret_rotated' => true]);
        return response()->json(['data' => $subscription->fresh(), 'secret' => $secret, 'status' => 'rotated']);
    }

    private function companyId(): int
    {
        $companyId = (int) (auth()->user()?->company_id ?? 0);
        abort_unless($companyId, 403, 'A company is required for webhook administration.');
        return $companyId;
    }
}
