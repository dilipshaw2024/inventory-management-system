<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IntegrationWebhookDelivery;
use App\Models\IntegrationWebhookSubscription;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDeliveryIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_id_is_unique_per_subscription_and_is_sent_as_an_idempotency_header(): void
    {
        $company = Company::create(['name' => 'Webhook Idempotency Co', 'code' => 'WEBHOOK-IDEMPOTENCY']);
        IntegrationWebhookSubscription::create([
            'company_id' => $company->id,
            'name' => 'ERP consumer',
            'endpoint_url' => 'https://erp.example.test/events',
            'secret' => 'webhook-secret-123456',
            'event_types' => ['inventory.updated'],
            'is_active' => true,
        ]);

        $webhooks = app(WebhookService::class);
        $webhooks->dispatch($company->id, 'inventory.updated', ['product_id' => 7], 'audit_log:42');
        $webhooks->dispatch($company->id, 'inventory.updated', ['product_id' => 7], 'audit_log:42');

        $this->assertDatabaseCount('integration_webhook_deliveries', 1);
        $delivery = IntegrationWebhookDelivery::firstOrFail();
        $this->assertSame('audit_log:42', $delivery->event_id);
        $this->assertSame('audit_log:42', data_get($delivery->payload, 'event_id'));

        Http::fake(['https://erp.example.test/*' => Http::response(['accepted' => true], 200)]);
        $this->assertTrue($webhooks->deliver($delivery));
        Http::assertSent(fn ($request): bool => $request->header('X-ERP-Event-Id')[0] === 'audit_log:42');
    }

    public function test_secret_rotation_returns_only_the_new_secret_and_is_audited(): void
    {
        $company = Company::create(['name' => 'Webhook Rotation Co', 'code' => 'WEBHOOK-ROTATION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $subscription = IntegrationWebhookSubscription::create([
            'company_id' => $company->id,
            'name' => 'Rotating consumer',
            'endpoint_url' => 'https://erp.example.test/rotate',
            'secret' => 'old-webhook-secret-123456',
            'event_types' => ['*'],
            'is_active' => true,
        ]);
        $token = $user->createToken('webhook-rotation', ['integration:write'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/integration/webhooks/'.$subscription->id.'/rotate-secret', ['secret' => 'new-webhook-secret-123456']);
        $response->assertOk()->assertJsonPath('status', 'rotated')->assertJsonPath('secret', 'new-webhook-secret-123456')->assertJsonMissing(['secret' => 'old-webhook-secret-123456']);
        $this->assertSame('new-webhook-secret-123456', $subscription->fresh()->secret);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'integration_webhook.secret_rotated', 'auditable_id' => $subscription->id]);
    }

    public function test_fifth_failed_delivery_enters_dead_letter_and_can_be_requeued(): void
    {
        $company = Company::create(['name' => 'Webhook Dead Letter Co', 'code' => 'WEBHOOK-DEAD-LETTER']);
        $subscription = IntegrationWebhookSubscription::create([
            'company_id' => $company->id,
            'name' => 'Unavailable consumer',
            'endpoint_url' => 'https://erp.example.test/unavailable',
            'secret' => 'dead-letter-secret-123456',
            'event_types' => ['inventory.updated'],
            'is_active' => true,
        ]);
        $webhooks = app(WebhookService::class);
        $webhooks->dispatch($company->id, 'inventory.updated', ['product_id' => 9], 'audit_log:99');
        $delivery = IntegrationWebhookDelivery::firstOrFail();
        Http::fake(['https://erp.example.test/*' => Http::response(['accepted' => false], 503)]);

        for ($attempt = 0; $attempt < 5; $attempt++) $this->assertFalse($webhooks->deliver($delivery->fresh()));
        $this->assertSame('dead_letter', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->dead_lettered_at);

        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('webhook-retry', ['integration:write'])->plainTextToken;
        $this->withToken($token)->postJson('/api/integration/webhooks/deliveries/'.$delivery->id.'/retry')
            ->assertOk()->assertJsonPath('status', 'pending');
        $this->assertNull($delivery->fresh()->dead_lettered_at);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'integration_webhook.requeued', 'auditable_id' => $delivery->id]);
    }

    public function test_subscription_endpoint_and_events_can_be_updated_without_exposing_the_secret(): void
    {
        $company = Company::create(['name' => 'Webhook Update Co', 'code' => 'WEBHOOK-UPDATE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $subscription = IntegrationWebhookSubscription::create([
            'company_id' => $company->id,
            'name' => 'Original consumer',
            'endpoint_url' => 'https://erp.example.test/original',
            'secret' => 'unchanged-webhook-secret-123456',
            'event_types' => ['inventory.updated'],
            'is_active' => true,
        ]);
        $token = $user->createToken('webhook-update', ['integration:write'])->plainTextToken;

        $response = $this->withToken($token)->patchJson('/api/integration/webhooks/'.$subscription->id, [
            'name' => 'Updated consumer',
            'endpoint_url' => 'https://erp.example.test/updated',
            'event_types' => ['inventory.updated', 'sales.approved', 'sales.approved'],
        ]);
        $response->assertOk()->assertJsonPath('status', 'updated')->assertJsonPath('data.name', 'Updated consumer')->assertJsonPath('data.endpoint_url', 'https://erp.example.test/updated')->assertJsonMissing(['secret' => 'unchanged-webhook-secret-123456']);
        $this->assertSame('unchanged-webhook-secret-123456', $subscription->fresh()->secret);
        $this->assertSame(['inventory.updated', 'sales.approved'], $subscription->fresh()->event_types);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'action' => 'integration_webhook.updated', 'auditable_id' => $subscription->id]);
    }
}
