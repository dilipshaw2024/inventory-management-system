<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryBatch;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\StockReservation;
use App\Models\Unit;
use App\Models\Category;
use App\Models\User;
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
}
