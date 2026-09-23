<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DeliveryOperation;
use App\Models\DeliveryTrackingEvent;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use App\Services\ErpSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeliveryIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_feed_can_filter_by_parent_partial_fulfillment(): void
    {
        $company = Company::create(['name' => 'Delivery Feed Co', 'code' => 'DELIVERY-FEED']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Delivery Customer', 'is_active' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-DELIVERY-FEED', 'date' => now()->toDateString(), 'status' => 'partially_delivered']);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-DELIVERY-FEED', 'date' => now()->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'dispatched']);
        DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'dispatch', 'status' => 'completed', 'performed_by' => $user->id, 'completed_at' => now()]);
        Sanctum::actingAs($user, ['sales:read', 'sales:write']);

        $response = $this->getJson('/api/integration/deliveries?order_status=partially_delivered');

        $response->assertOk()->assertJsonPath('data.0.id', $delivery->id)->assertJsonPath('data.0.sales_order.status', 'partially_delivered');
        $event = $this->postJson('/api/integration/delivery-tracking-events', ['delivery_id' => $delivery->id, 'external_reference' => 'TRACK-EVENT-1', 'carrier_status' => 'in_transit', 'event_at' => now()->toDateTimeString(), 'event_location' => 'Mumbai Hub']);
        $event->assertCreated()->assertJsonPath('status', 'recorded')->assertJsonPath('data.carrier_status', 'in_transit');
        $replayed = $this->postJson('/api/integration/delivery-tracking-events', ['delivery_id' => $delivery->id, 'external_reference' => 'TRACK-EVENT-1', 'carrier_status' => 'delivered', 'event_at' => now()->toDateTimeString()]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $event->json('data.id'));
        $this->assertSame(1, \App\Models\DeliveryTrackingEvent::where('company_id', $company->id)->count());
        $this->getJson('/api/integration/delivery-tracking-events?carrier_status=in_transit')->assertOk()->assertJsonPath('data.0.external_reference', 'TRACK-EVENT-1');
        $confirmed = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/delivered', ['proof_of_delivery' => 'POD-SIGNATURE-1', 'delivered_at' => now()->toDateTimeString()]);
        $confirmed->assertOk()->assertJsonPath('status', 'delivered')->assertJsonPath('data.proof_of_delivery', 'POD-SIGNATURE-1');
    }

    public function test_delivery_creation_replay_returns_the_original_delivery(): void
    {
        $company = Company::create(['name' => 'Delivery Replay Co', 'code' => 'DELIVERY-REPLAY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Delivery Replay Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Delivery Replay Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Delivery Replay Each', 'code' => 'EA-DELIVERY-REPLAY', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Delivery Replay Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Delivery Replay Item', 'status' => 1, 'is_stock_item' => true, 'can_sell' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-DELIVERY-REPLAY', 'date' => '2026-09-20', 'status' => 'approved']);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 3, 'delivered_qty' => 0, 'unit_price' => 10]);
        Sanctum::actingAs($user, ['sales:write']);
        $payload = ['external_reference' => 'DELIVERY-REPLAY-1', 'sales_order_id' => $order->id, 'date' => '2026-09-20', 'lines' => [['sales_order_line_id' => $orderLine->id, 'quantity' => 2, 'unit_price' => 10]]];

        $created = $this->postJson('/api/integration/deliveries', $payload);
        $created->assertCreated()->assertJsonPath('status', 'pending_approval');
        $replayed = $this->postJson('/api/integration/deliveries', $payload + ['lines' => [['sales_order_line_id' => $orderLine->id, 'quantity' => 99, 'unit_price' => 99]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, Delivery::where('company_id', $company->id)->count());
    }

    public function test_delivery_tracking_summary_reports_carrier_sla_metrics(): void
    {
        $company = Company::create(['name' => 'Carrier Summary Co', 'code' => 'CARRIER-SUMMARY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Carrier Branch', 'code' => 'CARRIER-BRANCH']);
        $store = Store::create(['branch_id' => $branch->id, 'name' => 'Carrier Store', 'code' => 'CARRIER-STORE']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Summary Customer', 'is_active' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'store_id' => $store->id, 'order_no' => 'SO-CARRIER-SUMMARY', 'date' => now()->subDays(3)->toDateString(), 'status' => 'approved']);
        $delivered = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-SUMMARY-1', 'carrier' => 'Carrier One', 'tracking_no' => 'TRACK-SUMMARY-1', 'date' => now()->subDays(3)->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'delivered']);
        $overdue = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-SUMMARY-2', 'carrier' => 'Carrier One', 'tracking_no' => 'TRACK-SUMMARY-2', 'date' => now()->subDays(3)->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'dispatched']);
        DeliveryTrackingEvent::create(['company_id' => $company->id, 'delivery_id' => $delivered->id, 'provider' => 'generic', 'external_reference' => 'SUMMARY-PICKUP', 'carrier_status' => 'in_transit', 'event_at' => now()->subHours(27)]);
        DeliveryTrackingEvent::create(['company_id' => $company->id, 'delivery_id' => $delivered->id, 'provider' => 'generic', 'external_reference' => 'SUMMARY-DELIVERED', 'carrier_status' => 'delivered', 'event_at' => now()->subHours(24)]);
        app(ErpSettingService::class)->put('carrier_sla_hours', ['Carrier One' => 2], 'json', $company->id);
        app(ErpSettingService::class)->put('branch_carrier_sla_hours', [(string) $branch->id => ['Carrier One' => 4]], 'json', $company->id);
        Sanctum::actingAs($user, ['sales:read']);

        $this->getJson('/api/integration/delivery-tracking-summary?overdue_hours=48')
            ->assertOk()->assertJsonPath('summary.deliveries', 2)->assertJsonPath('summary.delivered', 1)
            ->assertJsonPath('summary.overdue_in_transit', 1)->assertJsonPath('summary.delivery_events', 2)
            ->assertJsonPath('summary.average_transit_hours', 3)
            ->assertJsonPath('by_carrier.0.carrier', 'Carrier One')->assertJsonPath('by_carrier.0.count', 2)
            ->assertJsonPath('by_carrier.0.delivered', 1)->assertJsonPath('by_carrier.0.in_transit', 1)
            ->assertJsonPath('by_carrier.0.sla_hours', 4)->assertJsonPath('by_carrier.0.overdue_in_transit', 1)->assertJsonPath('by_carrier.0.delivery_rate', 50)
            ->assertJsonPath('by_carrier.0.on_time_delivered', 1)->assertJsonPath('by_carrier.0.on_time_rate', 100)
            ->assertJsonPath('by_carrier.0.average_transit_hours', 3)
            ->assertJsonPath('overdue_deliveries.0.delivery_no', 'DN-SUMMARY-2');
    }

    public function test_provider_discovery_exposes_carrier_polling_readiness(): void
    {
        $company = Company::create(['name' => 'Provider Discovery Co', 'code' => 'PROVIDER-DISCOVERY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Config::set('integrations.carrier_tracking_http_endpoint', 'https://carrier.test/track/{tracking_number}');
        Sanctum::actingAs($user, ['integration:read']);

        $this->getJson('/api/integration/providers')->assertOk()
            ->assertJsonPath('data.carrier_tracking.providers.0.key', 'generic')
            ->assertJsonPath('data.carrier_tracking.providers.0.supports_polling', false)
            ->assertJsonPath('data.carrier_tracking.providers.1.key', 'http')
            ->assertJsonPath('data.carrier_tracking.providers.1.supports_polling', true)
            ->assertJsonPath('data.carrier_tracking.providers.1.ready', true);
    }

    public function test_carrier_delivered_event_synchronizes_dispatched_delivery(): void
    {
        $company = Company::create(['name' => 'Carrier Sync Co', 'code' => 'CARRIER-SYNC']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Carrier Customer', 'is_active' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-CARRIER-SYNC', 'date' => now()->toDateString(), 'status' => 'partially_delivered']);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-CARRIER-SYNC', 'date' => now()->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'dispatched']);
        DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'dispatch', 'status' => 'completed', 'performed_by' => $user->id, 'completed_at' => now()]);
        Sanctum::actingAs($user, ['sales:write', 'sales:read']);

        $eventAt = now()->subMinute()->toDateTimeString();
        $response = $this->postJson('/api/integration/delivery-tracking-events', ['delivery_id' => $delivery->id, 'external_reference' => 'TRACK-CARRIER-DELIVERED', 'carrier_status' => 'delivered', 'event_at' => $eventAt]);

        $response->assertCreated()->assertJsonPath('data.carrier_status', 'delivered');
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id, 'fulfillment_status' => 'delivered']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery.delivered_by_carrier']);
    }

    public function test_generic_provider_payload_is_normalized_and_retained(): void
    {
        $company = Company::create(['name' => 'Carrier Payload Co', 'code' => 'CARRIER-PAYLOAD']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Payload Customer', 'is_active' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-CARRIER-PAYLOAD', 'date' => now()->toDateString(), 'status' => 'approved']);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-CARRIER-PAYLOAD', 'date' => now()->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'dispatched']);
        DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'dispatch', 'status' => 'completed', 'performed_by' => $user->id, 'completed_at' => now()]);
        Sanctum::actingAs($user, ['sales:write', 'sales:read']);

        $response = $this->postJson('/api/integration/delivery-tracking-events', ['provider' => 'GENERIC', 'payload' => ['deliveryId' => $delivery->id, 'status' => 'in_transit', 'occurred_at' => now()->toDateTimeString(), 'event_id' => 'RAW-CARRIER-1', 'location' => 'Mumbai Hub']]);

        $response->assertCreated()->assertJsonPath('data.provider', 'generic')->assertJsonPath('data.external_reference', 'RAW-CARRIER-1');
        $this->getJson('/api/integration/delivery-tracking-events?provider=generic')->assertOk()->assertJsonPath('data.0.external_reference', 'RAW-CARRIER-1');
        $this->assertDatabaseHas('delivery_tracking_events', ['delivery_id' => $delivery->id, 'provider' => 'generic', 'external_reference' => 'RAW-CARRIER-1']);
    }

    public function test_http_carrier_polling_normalizes_and_idempotently_records_events(): void
    {
        $company = Company::create(['name' => 'HTTP Carrier Co', 'code' => 'HTTP-CARRIER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'HTTP Customer', 'is_active' => true]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-HTTP-CARRIER', 'date' => now()->toDateString(), 'status' => 'approved']);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-HTTP-CARRIER', 'tracking_no' => 'TRACK-HTTP-1', 'date' => now()->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'dispatched']);
        DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'dispatch', 'status' => 'completed', 'performed_by' => $user->id, 'completed_at' => now()]);
        Config::set('integrations.carrier_tracking_http_endpoint', null);
        Sanctum::actingAs($user, ['sales:write', 'sales:read', 'integration:write']);
        $configured = $this->postJson('/api/integration/carrier-tracking-providers', ['provider' => 'HTTP', 'connection_config' => ['endpoint' => 'https://carrier.test/track/{tracking_number}', 'token' => 'carrier-secret']])
            ->assertCreated()->assertJsonPath('data.provider', 'http');
        $this->assertArrayNotHasKey('connection_config', $configured->json('data'));
        $this->assertNotSame('carrier-secret', (string) $this->app['db']->table('carrier_tracking_provider_settings')->where('id', $configured->json('data.id'))->value('connection_config'));
        Http::fake(['https://carrier.test/track/TRACK-HTTP-1*' => Http::response(['events' => [['status' => 'in_transit', 'occurred_at' => '2026-09-20 12:00:00', 'event_id' => 'HTTP-EVENT-1', 'location' => 'Carrier hub']]], 200)]);

        $response = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/delivery-tracking/sync', ['provider' => 'http']);
        $response->assertOk()->assertJsonPath('status', 'synchronized')->assertJsonPath('summary.recorded', 1)->assertJsonPath('data.0.external_reference', 'HTTP-EVENT-1');
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer carrier-secret'));
        $this->assertDatabaseHas('delivery_tracking_events', ['company_id' => $company->id, 'provider' => 'http', 'external_reference' => 'HTTP-EVENT-1']);

        $this->postJson('/api/integration/deliveries/'.$delivery->id.'/delivery-tracking/sync', ['provider' => 'http'])
            ->assertOk()->assertJsonPath('summary.recorded', 0)->assertJsonPath('summary.duplicates', 1);
    }

    public function test_sales_return_can_reverse_a_delivered_delivery(): void
    {
        $company = Company::create(['name' => 'Delivery Return Co', 'code' => 'DELIVERY-RETURN']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Return Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Return Product Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Delivery Return Each', 'status' => 1]);
        $category = Category::create(['name' => 'Delivery Return Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Delivered return item', 'quantity' => 0, 'status' => 1]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-DELIVERY-RETURN', 'date' => now()->toDateString(), 'status' => 'delivered']);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 2, 'delivered_qty' => 2, 'unit_price' => 10]);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-DELIVERY-RETURN', 'date' => now()->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'delivered']);
        $delivery->lines()->create(['sales_order_line_id' => $orderLine->id, 'product_id' => $product->id, 'delivered_qty' => 2, 'unit_price' => 10]);

        Sanctum::actingAs($creator, ['sales:write']);
        $returnPayload = ['return_type' => 'sales', 'external_reference' => 'RETURN-DELIVERY-REPLAY-1', 'customer_id' => $customer->id, 'source_delivery_id' => $delivery->id, 'date' => now()->toDateString(), 'reason_code' => 'customer_return', 'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]]];
        $created = $this->postJson('/api/integration/returns', $returnPayload);
        $created->assertCreated();
        $replayed = $this->postJson('/api/integration/returns', $returnPayload + ['lines' => [['product_id' => $product->id, 'quantity' => 99, 'unit_price' => 99]]]);
        $replayed->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, \App\Models\InventoryReturn::where('company_id', $company->id)->count());

        Sanctum::actingAs($checker, ['sales:write']);
        $approved = $this->postJson('/api/integration/returns/'.$created->json('data.id').'/approve');
        $approved->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('inventory_returns', ['source_delivery_id' => $delivery->id, 'status' => 'approved']);
    }

    public function test_delivery_packages_capture_contents_idempotently_and_prevent_overpacking(): void
    {
        $company = Company::create(['name' => 'Package Co', 'code' => 'PACKAGE-CO']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Package Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Package Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Package Each', 'status' => 1]);
        $category = Category::create(['name' => 'Package Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Package item', 'quantity' => 0, 'status' => 1]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-PACKAGE', 'date' => now()->toDateString(), 'status' => 'approved']);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 2, 'delivered_qty' => 2, 'unit_price' => 10]);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-PACKAGE', 'date' => now()->toDateString(), 'status' => 'approved', 'fulfillment_status' => 'dispatched']);
        DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'dispatch', 'status' => 'completed', 'performed_by' => $user->id, 'completed_at' => now()]);
        $deliveryLine = $delivery->lines()->create(['sales_order_line_id' => $orderLine->id, 'product_id' => $product->id, 'delivered_qty' => 2, 'unit_price' => 10]);
        Sanctum::actingAs($user, ['sales:read', 'sales:write']);

        $created = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/packages', [
            'external_reference' => 'PACKAGE-EXT-1', 'carrier' => 'Test Carrier', 'weight' => 1.25, 'weight_unit' => 'kg',
            'lines' => [['delivery_line_id' => $deliveryLine->id, 'quantity' => 1]],
        ]);
        $created->assertCreated()->assertJsonPath('status', 'packed')->assertJsonPath('data.status', 'dispatched')->assertJsonPath('data.lines.0.quantity', '1.000000');
        $packageId = $created->json('data.id');

        $duplicate = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/packages', [
            'external_reference' => 'PACKAGE-EXT-1', 'lines' => [['delivery_line_id' => $deliveryLine->id, 'quantity' => 1]],
        ]);
        $duplicate->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $packageId);

        $overpacked = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/packages', [
            'external_reference' => 'PACKAGE-EXT-2', 'lines' => [['delivery_line_id' => $deliveryLine->id, 'quantity' => 1.1]],
        ]);
        $overpacked->assertStatus(422);

        $this->getJson('/api/integration/deliveries/'.$delivery->id.'/packages')->assertOk()->assertJsonPath('data.0.id', $packageId)->assertJsonPath('data.0.lines.0.product_id', $product->id);
        $this->getJson('/api/integration/deliveries?package_status=dispatched')->assertOk()->assertJsonPath('data.0.packages.0.id', $packageId);
        $this->postJson('/api/integration/deliveries/'.$delivery->id.'/delivered', ['proof_of_delivery' => 'POD-PACKAGE'])->assertOk();
        $this->assertDatabaseHas('delivery_packages', ['id' => $packageId, 'status' => 'delivered']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'delivery.package.created']);
    }
}
