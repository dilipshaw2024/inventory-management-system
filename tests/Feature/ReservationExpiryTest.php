<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryOperation;
use App\Models\InventoryMovement;
use App\Models\InventoryBatch;
use App\Models\InventoryCostLayer;
use App\Models\InventorySerial;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Models\Category;
use App\Models\User;
use App\Services\StockReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_reservations_are_released_and_no_longer_reduce_available_stock(): void
    {
        $company = Company::create(['name' => 'Reservation Expiry Co', 'code' => 'RES-EXPIRY']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Reservation supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-RES', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Reservation category', 'status' => 1]);
        StockReservation::create([
            'company_id' => $company->id,
            'product_id' => ($product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Expiring item', 'quantity' => 10, 'purchase_price' => 4, 'status' => 1]))->id,
            'quantity' => 6,
            'released_quantity' => 0,
            'status' => 'active',
            'expires_at' => now()->subMinute(),
        ]);

        $reservationId = StockReservation::query()->latest('id')->value('id');
        $this->assertSame(10.0, app(\App\Services\InventoryAvailabilityService::class)->available($product, true, null, $company->id));
        Artisan::call('erp:inventory:expire-reservations', ['--company' => $company->id]);

        $this->assertDatabaseHas('stock_reservations', ['id' => $reservationId, 'status' => 'released', 'released_quantity' => 6]);
        $this->assertSame(10.0, app(\App\Services\InventoryAvailabilityService::class)->available($product, true, null, $company->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock_reservation.expired', 'auditable_id' => $reservationId]);
    }

    public function test_reservation_expiry_setting_is_tenant_scoped_and_visible_to_api_clients(): void
    {
        $company = Company::create(['name' => 'Reservation Settings Co', 'code' => 'RES-SETTINGS']);
        $user = User::factory()->create(['company_id' => $company->id]);
        Sanctum::actingAs($user, ['accounting:read', 'accounting:write']);

        $this->patchJson('/api/accounting/settings', ['reservation_expiry_days' => 14])
            ->assertOk()->assertJsonPath('data.reservation_expiry_days', 14);
        $this->getJson('/api/accounting/settings')
            ->assertOk()->assertJsonPath('data.reservation_expiry_days', 14);
    }

    public function test_sales_order_can_reserve_an_explicit_batch_instead_of_fefo_fallback(): void
    {
        $company = Company::create(['name' => 'Batch Reservation Co', 'code' => 'BATCH-RES']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Batch supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each batch', 'code' => 'EA-BATCH', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Batch category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Batch item', 'quantity' => 10, 'purchase_price' => 5, 'status' => 1, 'tracking_type' => 'batch']);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'BATCH-EXPLICIT', 'lot_no' => 'LOT-EXPLICIT']);
        InventoryCostLayer::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'original_quantity' => 10, 'remaining_quantity' => 10, 'unit_cost' => 5, 'received_at' => now()]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Batch customer', 'status' => 1]);
        $order = SalesOrder::create(['company_id' => $company->id, 'order_no' => 'SO-BATCH-1', 'customer_id' => $customer->id, 'date' => '2026-09-18', 'status' => 'approved', 'allow_backorders' => false, 'created_by' => $user->id]);
        $order->lines()->create(['product_id' => $product->id, 'batch_id' => $batch->id, 'ordered_qty' => 4, 'unit_price' => 10]);

        app(\App\Services\StockReservationService::class)->reserveSalesOrder($order->fresh('lines'));

        $this->assertDatabaseHas('stock_reservations', ['sales_order_line_id' => $order->lines()->value('id'), 'product_id' => $product->id, 'batch_id' => $batch->id, 'quantity' => 4, 'status' => 'active']);
    }
    public function test_open_batch_reservation_can_be_assigned_explicitly_with_stock_guard(): void
    {
        $company = Company::create(['name' => 'Batch Assignment Co', 'code' => 'BATCH-ASSIGN']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Assignment supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Assignment Each', 'code' => 'EA-ASSIGN', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Assignment category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Assignable batch item', 'quantity' => 8, 'purchase_price' => 5, 'status' => 1, 'tracking_type' => 'batch']);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'ASSIGN-BATCH']);
        InventoryCostLayer::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'original_quantity' => 8, 'remaining_quantity' => 8, 'unit_cost' => 5, 'received_at' => now()]);
        $reservation = StockReservation::create(['company_id' => $company->id, 'product_id' => $product->id, 'quantity' => 3, 'released_quantity' => 0, 'status' => 'active']);
        Sanctum::actingAs($user, ['inventory:write']);

        $this->patchJson('/api/inventory/reservations/'.$reservation->id.'/batch', ['batch_id' => $batch->id])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('data.batch_id', $batch->id);
        $this->assertDatabaseHas('stock_reservations', ['id' => $reservation->id, 'batch_id' => $batch->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock_reservation.batch_assigned', 'auditable_id' => $reservation->id]);
    }

    public function test_delivery_approval_consumes_explicit_reserved_serial_and_releases_reservation(): void
    {
        $company = Company::create(['name' => 'Serial Delivery Co', 'code' => 'SERIAL-DELIVERY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Delivery serial supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Delivery Serial Each', 'code' => 'EA-DELIVERY-SERIAL', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Delivery serial category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Reserved serial delivery item', 'quantity' => 1, 'purchase_price' => 5, 'status' => 1, 'tracking_type' => 'serial']);
        $serial = InventorySerial::create(['product_id' => $product->id, 'serial_no' => 'DELIVERY-SN-001', 'status' => 'available']);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 1, 'unit_cost' => 5, 'reason' => 'Serial delivery fixture']);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Serial delivery customer', 'status' => 1]);
        $order = SalesOrder::create(['company_id' => $company->id, 'order_no' => 'SO-SERIAL-DELIVERY', 'customer_id' => $customer->id, 'date' => '2026-09-30', 'status' => 'approved']);
        $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 1, 'delivered_qty' => 0, 'unit_price' => 10]);
        $reservation = StockReservation::create(['company_id' => $company->id, 'product_id' => $product->id, 'sales_order_line_id' => $orderLine->id, 'quantity' => 1, 'released_quantity' => 0, 'status' => 'active']);
        app(StockReservationService::class)->assignSerials($reservation, [$serial->id]);
        $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-SERIAL-DELIVERY', 'date' => '2026-09-30', 'status' => 'pending', 'fulfillment_status' => 'pending']);
        $deliveryLine = $delivery->lines()->create(['sales_order_line_id' => $orderLine->id, 'product_id' => $product->id, 'delivered_qty' => 1, 'unit_price' => 10]);
        DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'pick', 'status' => 'completed', 'performed_by' => $user->id, 'completed_at' => now()]);
        DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'pack', 'status' => 'completed', 'performed_by' => $user->id, 'completed_at' => now()]);
        Sanctum::actingAs($user, ['sales:write']);

        $this->postJson('/api/integration/deliveries/'.$delivery->id.'/approve')
            ->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('inventory_serials', ['id' => $serial->id, 'status' => 'issued']);
        $this->assertDatabaseHas('stock_reservations', ['id' => $reservation->id, 'status' => 'released', 'released_quantity' => 1]);
        $this->assertDatabaseHas('inventory_movements', ['reference_id' => $delivery->id, 'movement_type' => 'issue', 'serial_id' => $serial->id]);
        $this->assertDatabaseHas('delivery_lines', ['id' => $deliveryLine->id, 'issued_serial_numbers' => 'DELIVERY-SN-001']);
    }

    public function test_open_serial_reservation_can_be_split_into_explicit_serial_assignments(): void
    {
        $company = Company::create(['name' => 'Serial Reservation Co', 'code' => 'SERIAL-RES']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Serial supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Serial Each', 'code' => 'EA-SERIAL', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Serial category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Assignable serial item', 'quantity' => 2, 'purchase_price' => 5, 'status' => 1, 'tracking_type' => 'serial']);
        $serialOne = InventorySerial::create(['product_id' => $product->id, 'serial_no' => 'ASSIGN-SN-001', 'status' => 'available']);
        $serialTwo = InventorySerial::create(['product_id' => $product->id, 'serial_no' => 'ASSIGN-SN-002', 'status' => 'available']);
        $reservation = StockReservation::create(['company_id' => $company->id, 'product_id' => $product->id, 'quantity' => 2, 'released_quantity' => 0, 'status' => 'active']);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $this->patchJson('/api/inventory/reservations/'.$reservation->id.'/serials', ['serial_ids' => [$serialOne->id, $serialTwo->id]])
            ->assertOk()->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('stock_reservations', ['id' => $reservation->id, 'quantity' => 1, 'serial_id' => $serialOne->id, 'status' => 'active']);
        $this->assertDatabaseHas('stock_reservations', ['product_id' => $product->id, 'quantity' => 1, 'serial_id' => $serialTwo->id, 'status' => 'active']);
        $this->assertSame(2, StockReservation::where('product_id', $product->id)->where('status', 'active')->count());
        $this->getJson('/api/inventory/reservations?serial_id='.$serialOne->id)->assertOk()->assertJsonPath('data.0.serial_id', $serialOne->id)->assertJsonPath('data.0.serial.serial_no', 'ASSIGN-SN-001');
        $this->assertDatabaseHas('inventory_serials', ['id' => $serialOne->id, 'status' => 'reserved']);
        app(StockReservationService::class)->releaseReservation(StockReservation::findOrFail($reservation->id), 1);
        $this->assertDatabaseHas('inventory_serials', ['id' => $serialOne->id, 'status' => 'available']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock_reservation.serials_assigned', 'auditable_id' => $reservation->id]);
    }
}
