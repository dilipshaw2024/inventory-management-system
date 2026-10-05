<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryBatch;
use App\Models\InventoryCostLayer;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\InventoryExpiryNotification;
use App\Notifications\InventoryExceptionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExpiryAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_alert_includes_cost_layer_value_at_risk(): void
    {
        $company = Company::create(['name' => 'Expiry Alert Co', 'code' => 'EXPIRY-ALERT']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Expiry Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Expiry Each', 'code' => 'EXPIRY-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Expiry Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Expiring Item', 'purchase_price' => 100, 'status' => 1]);
        $batch = InventoryBatch::create(['product_id' => $product->id, 'batch_no' => 'EXP-1', 'expiry_date' => now()->addDays(10)->toDateString()]);
        InventoryMovement::create(['company_id' => $company->id, 'product_id' => $product->id, 'movement_type' => 'receipt', 'quantity' => 5, 'unit_cost' => 42, 'batch_id' => $batch->id, 'reason' => 'Expiry alert fixture']);
        InventoryCostLayer::create(['product_id' => $product->id, 'batch_id' => $batch->id, 'original_quantity' => 5, 'remaining_quantity' => 5, 'unit_cost' => 42, 'received_at' => now()]);
        $permission = Permission::create(['code' => 'inventory.view', 'name' => 'View inventory', 'module' => 'inventory']);
        $role = Role::create(['code' => 'expiry-alert-reader', 'name' => 'Expiry alert reader', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $user->roles()->attach($role);

        Artisan::call('erp:inventory:expiry-alerts', ['--days' => 90, '--company' => $company->id]);

        $notification = $user->notifications()->where('type', InventoryExpiryNotification::class)->firstOrFail();
        $this->assertSame(5.0, (float) $notification->data['balance']);
        $this->assertSame(210.0, (float) $notification->data['value_at_risk']);
    }
    public function test_exception_alerts_cover_excess_stock_and_are_repeat_safe(): void
    {
        $company = Company::create(['name' => 'Exception Alert Co', 'code' => 'EXCEPTION-ALERT']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Exception Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Exception Each', 'code' => 'EXCEPTION-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Exception Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Excess Item', 'purchase_price' => 25, 'quantity' => 5, 'max_stock' => 2, 'status' => 1, 'is_stock_item' => true]);
        $permission = Permission::create(['code' => 'inventory.view', 'name' => 'View inventory', 'module' => 'inventory']);
        $role = Role::create(['code' => 'exception-alert-reader', 'name' => 'Exception alert reader', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $user->roles()->attach($role);

        $this->assertSame(0, Artisan::call('erp:inventory:exception-alerts', ['type' => 'excess', '--company' => $company->id]));
        $this->assertSame(0, Artisan::call('erp:inventory:exception-alerts', ['type' => 'excess', '--company' => $company->id]));

        $notification = $user->notifications()->where('type', InventoryExceptionNotification::class)->firstOrFail();
        $this->assertSame('excess', $notification->data['exception_type']);
        $this->assertSame(3.0, (float) $notification->data['excess_quantity']);
        $this->assertSame(1, $user->notifications()->where('type', InventoryExceptionNotification::class)->count());
    }
}
