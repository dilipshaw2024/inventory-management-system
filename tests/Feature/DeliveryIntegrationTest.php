<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DeliveryOperation;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->getJson('/api/integration/delivery-tracking-events?carrier_status=in_transit')->assertOk()->assertJsonPath('data.0.external_reference', 'TRACK-EVENT-1');
        $confirmed = $this->postJson('/api/integration/deliveries/'.$delivery->id.'/delivered', ['proof_of_delivery' => 'POD-SIGNATURE-1', 'delivered_at' => now()->toDateTimeString()]);
        $confirmed->assertOk()->assertJsonPath('status', 'delivered')->assertJsonPath('data.proof_of_delivery', 'POD-SIGNATURE-1');
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
        $created = $this->postJson('/api/integration/returns', ['return_type' => 'sales', 'customer_id' => $customer->id, 'source_delivery_id' => $delivery->id, 'date' => now()->toDateString(), 'reason_code' => 'customer_return', 'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]]]);
        $created->assertCreated();

        Sanctum::actingAs($checker, ['sales:write']);
        $approved = $this->postJson('/api/integration/returns/'.$created->json('data.id').'/approve');
        $approved->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseHas('inventory_returns', ['source_delivery_id' => $delivery->id, 'status' => 'approved']);
    }
}
