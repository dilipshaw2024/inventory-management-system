<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DeliveryOperation;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehousePickListTest extends TestCase
{
    use RefreshDatabase;

    public function test_pick_list_is_company_scoped_and_sorted_by_location(): void
    {
        $company = Company::create(['name' => 'Pick List Co', 'code' => 'PICK-LIST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Pick Branch', 'code' => 'PICK-BRANCH']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Pick Warehouse', 'code' => 'PICK-WH', 'is_active' => true]);
        $firstLocation = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'A Bin', 'code' => 'A-01', 'type' => 'bin', 'is_active' => true]);
        $secondLocation = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'B Bin', 'code' => 'B-01', 'type' => 'bin', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Pick Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Pick Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Pick Each', 'code' => 'EA-PICK', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Pick Category', 'status' => 1]);
        $firstProduct = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Z Product', 'sku' => 'Z-PICK', 'barcode' => 'SCAN-Z-PICK', 'status' => 1, 'is_stock_item' => true, 'can_sell' => true]);
        $secondProduct = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'A Product', 'sku' => 'A-PICK', 'barcode' => 'SCAN-A-PICK', 'status' => 1, 'is_stock_item' => true, 'can_sell' => true]);
        $firstOrder = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-PICK-1', 'date' => '2026-09-20', 'status' => 'approved', 'location_id' => $firstLocation->id]);
        $secondOrder = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-PICK-2', 'date' => '2026-09-20', 'status' => 'approved', 'location_id' => $secondLocation->id]);
        $firstLine = $firstOrder->lines()->create(['product_id' => $firstProduct->id, 'ordered_qty' => 2, 'delivered_qty' => 0, 'unit_price' => 10]);
        $secondLine = $secondOrder->lines()->create(['product_id' => $secondProduct->id, 'ordered_qty' => 3, 'delivered_qty' => 0, 'unit_price' => 20]);
        $firstDelivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $firstOrder->id, 'delivery_no' => 'DN-PICK-1', 'date' => '2026-09-20', 'location_id' => $firstLocation->id, 'status' => 'pending', 'fulfillment_status' => 'pending']);
        $secondDelivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $secondOrder->id, 'delivery_no' => 'DN-PICK-2', 'date' => '2026-09-20', 'location_id' => $secondLocation->id, 'status' => 'pending', 'fulfillment_status' => 'pending']);
        DeliveryLine::create(['delivery_id' => $firstDelivery->id, 'sales_order_line_id' => $firstLine->id, 'product_id' => $firstProduct->id, 'delivered_qty' => 2, 'unit_price' => 10]);
        DeliveryLine::create(['delivery_id' => $secondDelivery->id, 'sales_order_line_id' => $secondLine->id, 'product_id' => $secondProduct->id, 'delivered_qty' => 3, 'unit_price' => 20]);
        DeliveryOperation::create(['delivery_id' => $firstDelivery->id, 'operation_type' => 'pick', 'status' => 'pending']);
        DeliveryOperation::create(['delivery_id' => $secondDelivery->id, 'operation_type' => 'pick', 'status' => 'completed']);
        Sanctum::actingAs($user, ['warehouse:read']);

        $response = $this->getJson('/api/integration/warehouse/pick-list?warehouse_id='.$warehouse->id);
        $response->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('meta.total_lines', 1)->assertJsonPath('meta.total_quantity', 2)->assertJsonPath('data.0.delivery_no', 'DN-PICK-1')->assertJsonPath('data.0.location', 'A-01')->assertJsonPath('data.0.lines.0.product', 'Z Product')->assertJsonPath('data.0.pick_sequence', 1);
        $this->getJson('/api/integration/warehouse/pick-list?location_id='.$secondLocation->id)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_pick_list_completion_is_atomic_company_scoped_and_idempotent(): void
    {
        $company = Company::create(['name' => 'Pick Execution Co', 'code' => 'PICK-EXEC']);
        $otherCompany = Company::create(['name' => 'Other Pick Co', 'code' => 'PICK-OTHER']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Execution Branch', 'code' => 'EXEC-BRANCH']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Execution Warehouse', 'code' => 'EXEC-WH', 'is_active' => true]);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Execution Bin', 'code' => 'EXEC-01', 'type' => 'bin', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Execution Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Execution Each', 'code' => 'EA-EXEC', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Execution Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Execution Product', 'sku' => 'EXEC-PICK', 'status' => 1, 'is_stock_item' => true, 'can_sell' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Execution Customer', 'is_active' => true]);

        $makeDelivery = function (string $number, float $quantity) use ($company, $customer, $product, $location): Delivery {
            $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-'.$number, 'date' => '2026-09-20', 'status' => 'approved', 'location_id' => $location->id]);
            $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => $quantity, 'delivered_qty' => 0, 'unit_price' => 10]);
            $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => $number, 'date' => '2026-09-20', 'location_id' => $location->id, 'status' => 'pending', 'fulfillment_status' => 'pending']);
            DeliveryLine::create(['delivery_id' => $delivery->id, 'sales_order_line_id' => $orderLine->id, 'product_id' => $product->id, 'delivered_qty' => $quantity, 'unit_price' => 10]);
            DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'pick', 'status' => 'pending']);
            return $delivery;
        };
        $first = $makeDelivery('DN-EXEC-1', 2);
        $second = $makeDelivery('DN-EXEC-2', 3);

        Sanctum::actingAs($user, ['warehouse:write']);
        $this->postJson('/api/integration/warehouse/pick-list/complete', [
            'deliveries' => [
                ['delivery_id' => $first->id, 'confirmed_quantities' => [(string) $first->lines()->first()->id => 2]],
                ['delivery_id' => $second->id, 'confirmed_quantities' => [(string) $second->lines()->first()->id => 99]],
            ],
        ])->assertStatus(422);
        $this->assertDatabaseHas('delivery_operations', ['delivery_id' => $first->id, 'operation_type' => 'pick', 'status' => 'pending']);
        $this->assertDatabaseHas('delivery_operations', ['delivery_id' => $second->id, 'operation_type' => 'pick', 'status' => 'pending']);

        $payload = ['deliveries' => [
            ['delivery_id' => $first->id, 'scans' => [['code' => 'EXEC-PICK', 'quantity' => 2]]],
            ['delivery_id' => $second->id, 'scans' => [['code' => 'EXEC-PICK', 'quantity' => 3]]],
        ]];
        $this->postJson('/api/integration/warehouse/pick-list/complete', $payload)->assertOk()->assertJsonPath('meta.delivery_count', 2);
        $this->postJson('/api/integration/warehouse/pick-list/complete', $payload)->assertOk()->assertJsonPath('meta.delivery_count', 2);
        $this->assertDatabaseCount('delivery_operations', 4); // pick + auto-created pack for each delivery
        $this->assertDatabaseHas('delivery_operations', ['delivery_id' => $first->id, 'operation_type' => 'pick', 'status' => 'completed']);

        $foreignCustomer = Customer::create(['company_id' => $otherCompany->id, 'name' => 'Foreign Pick Customer', 'is_active' => true]);
        $foreignOrder = SalesOrder::create(['company_id' => $otherCompany->id, 'customer_id' => $foreignCustomer->id, 'order_no' => 'SO-FOREIGN', 'date' => '2026-09-20', 'status' => 'approved']);
        $foreignDelivery = Delivery::create(['company_id' => $otherCompany->id, 'sales_order_id' => $foreignOrder->id, 'delivery_no' => 'DN-FOREIGN', 'date' => '2026-09-20', 'status' => 'pending', 'fulfillment_status' => 'pending']);
        $this->postJson('/api/integration/warehouse/pick-list/complete', ['deliveries' => [['delivery_id' => $foreignDelivery->id]]])->assertStatus(422);
    }

    public function test_pick_wave_lifecycle_is_persistent_and_coordinates_delivery_picking(): void
    {
        $company = Company::create(['name' => 'Wave Co', 'code' => 'PICK-WAVE']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Wave Branch', 'code' => 'WAVE-BRANCH']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Wave Warehouse', 'code' => 'WAVE-WH', 'is_active' => true]);
        $location = InventoryLocation::create(['warehouse_id' => $warehouse->id, 'name' => 'Wave Bin', 'code' => 'WAVE-01', 'type' => 'bin', 'is_active' => true]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Wave Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Wave Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Wave Each', 'code' => 'EA-WAVE', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Wave Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Wave Product', 'sku' => 'WAVE-PICK', 'status' => 1, 'is_stock_item' => true, 'can_sell' => true]);
        $deliveries = collect([1, 2])->map(function (int $index) use ($company, $customer, $product, $location): Delivery {
            $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'SO-WAVE-'.$index, 'date' => '2026-09-20', 'status' => 'approved', 'location_id' => $location->id]);
            $orderLine = $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => $index, 'delivered_qty' => 0, 'unit_price' => 10]);
            $delivery = Delivery::create(['company_id' => $company->id, 'sales_order_id' => $order->id, 'delivery_no' => 'DN-WAVE-'.$index, 'date' => '2026-09-20', 'location_id' => $location->id, 'status' => 'pending', 'fulfillment_status' => 'pending']);
            DeliveryLine::create(['delivery_id' => $delivery->id, 'sales_order_line_id' => $orderLine->id, 'product_id' => $product->id, 'delivered_qty' => $index, 'unit_price' => 10]);
            DeliveryOperation::create(['delivery_id' => $delivery->id, 'operation_type' => 'pick', 'status' => 'pending']);
            return $delivery;
        });

        Sanctum::actingAs($user, ['warehouse:read', 'warehouse:write']);
        $create = $this->postJson('/api/integration/warehouse/pick-waves', ['warehouse_id' => $warehouse->id, 'delivery_ids' => $deliveries->pluck('id')->all(), 'external_reference' => 'WAVE-EXT-1']);
        $create->assertCreated()->assertJsonPath('status', 'created')->assertJsonPath('data.status', 'planned')->assertJsonPath('data.delivery_count', 2);
        $waveId = (int) $create->json('data.id');
        $this->postJson('/api/integration/warehouse/pick-waves', ['warehouse_id' => $warehouse->id, 'delivery_ids' => $deliveries->pluck('id')->all(), 'external_reference' => 'WAVE-EXT-1'])->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->postJson('/api/integration/warehouse/pick-waves/'.$waveId.'/cancel', ['cancellation_reason' => 'Picker reassigned'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $recreated = $this->postJson('/api/integration/warehouse/pick-waves', ['warehouse_id' => $warehouse->id, 'delivery_ids' => $deliveries->pluck('id')->all(), 'external_reference' => 'WAVE-EXT-2']);
        $recreated->assertCreated()->assertJsonPath('data.status', 'planned');
        $waveId = (int) $recreated->json('data.id');
        $this->postJson('/api/integration/warehouse/pick-waves/'.$waveId.'/release')->assertOk()->assertJsonPath('data.status', 'released');

        $payload = ['wave_id' => $waveId, 'deliveries' => $deliveries->map(fn (Delivery $delivery, int $index): array => ['delivery_id' => $delivery->id, 'confirmed_quantities' => [(string) $delivery->lines()->first()->id => $index + 1]])->values()->all()];
        $this->postJson('/api/integration/warehouse/pick-list/complete', $payload)->assertOk()->assertJsonPath('meta.wave_id', $waveId);
        $this->postJson('/api/integration/warehouse/pick-waves/'.$waveId.'/complete')->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.completed_pick_count', 2);
        $this->getJson('/api/integration/warehouse/pick-waves?status=completed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.wave_no', $recreated->json('data.wave_no'));
    }
}
