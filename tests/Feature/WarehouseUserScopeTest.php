<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class WarehouseUserScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_assign_warehouse_scope_and_feed_exposes_it(): void
    {
        $company = Company::create(['name' => 'Warehouse Scope Co', 'code' => 'WH-SCOPE']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Main Branch', 'code' => 'WH-BRANCH']);
        $warehouse = Warehouse::create(['branch_id' => $branch->id, 'name' => 'Primary Warehouse', 'code' => 'WH-PRIMARY']);
        $admin = User::factory()->create(['company_id' => $company->id]);
        $operator = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
        $token = $admin->createToken('warehouse-scope-test', ['integration:read', 'integration:write'])->plainTextToken;

        $this->withToken($token)->patchJson('/api/integration/security/users/'.$operator->id.'/warehouse-scope', [
            'warehouse_id' => $warehouse->id,
        ])->assertOk()
            ->assertJsonPath('data.warehouse_id', $warehouse->id)
            ->assertJsonPath('data.warehouse.code', 'WH-PRIMARY');

        $feed = $this->withToken($token)->getJson('/api/integration/security/users?updated_since=2000-01-01')->assertOk();
        $operatorRow = collect($feed->json('data'))->firstWhere('id', $operator->id);
        $this->assertSame($warehouse->id, $operatorRow['warehouse_id']);
    }

    public function test_warehouse_scoped_user_reads_and_writes_only_assigned_warehouse(): void
    {
        $company = Company::create(['name' => 'Warehouse Isolation Co', 'code' => 'WH-ISOLATION']);
        $branch = Branch::create(['company_id' => $company->id, 'name' => 'Operations Branch', 'code' => 'WH-OPS']);
        $first = Warehouse::create(['branch_id' => $branch->id, 'name' => 'North Warehouse', 'code' => 'WH-NORTH']);
        $second = Warehouse::create(['branch_id' => $branch->id, 'name' => 'South Warehouse', 'code' => 'WH-SOUTH']);
        $north = InventoryLocation::create(['warehouse_id' => $first->id, 'name' => 'North Bin', 'code' => 'WH-NORTH-BIN', 'type' => 'bin', 'is_active' => true]);
        $south = InventoryLocation::create(['warehouse_id' => $second->id, 'name' => 'South Bin', 'code' => 'WH-SOUTH-BIN', 'type' => 'bin', 'is_active' => true]);
        $operator = User::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'warehouse_id' => $first->id]);
        Auth::login($operator);

        $this->assertSame([$first->id], Warehouse::query()->pluck('id')->all());
        $this->assertSame([$north->id], InventoryLocation::query()->pluck('id')->all());

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        InventoryLocation::create(['warehouse_id' => $second->id, 'name' => 'Blocked Bin', 'code' => 'WH-BLOCKED', 'type' => 'bin', 'is_active' => true]);
    }
}
