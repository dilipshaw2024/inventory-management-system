<?php

namespace Tests\Feature;

use App\Models\ApprovalAction;
use App\Models\ApprovalPolicy;
use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePart;
use App\Models\Product;
use App\Models\ServiceAsset;
use App\Models\StockReservation;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaintenancePartApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_maintenance_part_consumption_requires_an_independent_checker_when_policy_exists(): void
    {
        $company = Company::create(['name' => 'Service Approval Co', 'code' => 'SERVICE-APPROVAL']);
        $maker = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Service Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Service Parts', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id,
            'category_id' => $category->id, 'name' => 'Service filter', 'sku' => 'SERVICE-FILTER-001',
            'tracking_type' => 'none', 'quantity' => 5, 'status' => 1, 'purchase_price' => 12,
        ]);
        $asset = ServiceAsset::create([
            'company_id' => $company->id, 'asset_no' => 'ASSET-SERVICE-001', 'name' => 'Service asset', 'status' => 'active',
        ]);
        $order = MaintenanceOrder::create([
            'company_id' => $company->id, 'order_no' => 'MO-SERVICE-001', 'asset_id' => $asset->id,
            'maintenance_type' => 'corrective', 'status' => 'planned', 'created_by' => $maker->id,
        ]);
        ApprovalPolicy::create([
            'company_id' => $company->id, 'document_type' => MaintenanceOrder::class,
            'required_permission' => '', 'approval_step' => 1, 'is_active' => true,
        ]);
        InventoryMovement::create([
            'company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt',
            'quantity' => 5, 'unit_cost' => 12, 'reason' => 'Service approval fixture',
        ]);

        Sanctum::actingAs($maker, ['service:write']);
        $blocked = $this->postJson('/api/service/orders/'.$order->id.'/parts', [
            'product_id' => $product->id, 'quantity' => 1,
        ]);

        $blocked->assertStatus(422)->assertJsonPath('message', 'Maker-checker control: the creator cannot approve this transaction.');
        $this->assertSame(5.0, (float) $product->fresh()->quantity);
        $this->assertSame(0, MaintenancePart::where('maintenance_order_id', $order->id)->count());
        $this->assertSame(1, InventoryMovement::where('product_id', $product->id)->count());
        $this->assertSame(0, ApprovalAction::where('document_id', $order->id)->count());

        Sanctum::actingAs($checker, ['service:write']);
        $consumed = $this->postJson('/api/service/orders/'.$order->id.'/parts', [
            'product_id' => $product->id, 'quantity' => 1,
        ]);

        $consumed->assertOk()->assertJsonPath('status', 'consumed');
        $this->assertSame(4.0, (float) $product->fresh()->quantity);
        $this->assertSame(1, MaintenancePart::where('maintenance_order_id', $order->id)->count());
        $this->assertSame(2, InventoryMovement::where('product_id', $product->id)->count());
        $this->assertSame(1, ApprovalAction::where('document_id', $order->id)->count());

        $reserved = $this->postJson('/api/service/orders/'.$order->id.'/parts/reserve', [
            'product_id' => $product->id, 'quantity' => 1,
        ]);
        $reserved->assertCreated()->assertJsonPath('status', 'reserved');
        $reservationId = StockReservation::where('source_type', MaintenanceOrder::class)
            ->where('source_id', $order->id)->latest('id')->value('id');
        $this->assertNotNull($reservationId);
        $this->assertDatabaseHas('stock_reservations', ['id' => $reservationId, 'status' => 'active', 'quantity' => 1]);

        $consumedReserved = $this->postJson('/api/service/orders/'.$order->id.'/parts', [
            'product_id' => $product->id, 'quantity' => 1,
        ]);
        $consumedReserved->assertOk()->assertJsonPath('status', 'consumed');
        $this->assertDatabaseHas('stock_reservations', ['id' => $reservationId, 'status' => 'released', 'released_quantity' => 1]);
        $this->assertSame(3.0, (float) $product->fresh()->quantity);
        $this->assertSame(3, InventoryMovement::where('product_id', $product->id)->count());

        $statusOrder = MaintenanceOrder::create([
            'company_id' => $company->id, 'order_no' => 'MO-SERVICE-002', 'asset_id' => $asset->id,
            'maintenance_type' => 'inspection', 'status' => 'planned', 'created_by' => $maker->id,
        ]);
        Sanctum::actingAs($maker, ['service:write']);
        $this->patchJson('/api/service/orders/'.$statusOrder->id.'/status', ['status' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Maker-checker control: the creator cannot approve this transaction.');
        $this->assertSame('planned', $statusOrder->fresh()->status);

        Sanctum::actingAs($checker, ['service:write']);
        $this->patchJson('/api/service/orders/'.$statusOrder->id.'/status', ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('status', 'in_progress');
    }
}
