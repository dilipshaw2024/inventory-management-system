<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\ErpSettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockCountMultiCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_counters_must_complete_before_count_approval(): void
    {
        $company = Company::create(['name' => 'Multi Counter Co', 'code' => 'MULTI-COUNT']);
        app(ErpSettingService::class)->put('stock_count_recount_variance_percent', 5, 'float', $company->id);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $counterOne = User::factory()->create(['company_id' => $company->id]);
        $counterTwo = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Multi Counter Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-MULTI', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Multi Counter Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Multi counter item', 'quantity' => 10, 'purchase_price' => 4, 'status' => 1]);

        Sanctum::actingAs($creator, ['inventory:write', 'inventory:read']);
        $countId = $this->postJson('/api/inventory/counts', [
            'count_no' => 'CNT-MULTI-1', 'count_date' => '2026-09-19',
            'lines' => [['product_id' => $product->id, 'counted_quantity' => 9]],
        ])->assertCreated()->json('data.id');

        $assigned = $this->postJson('/api/inventory/counts/'.$countId.'/assign-counters', [
            'assignments' => [['user_id' => $counterOne->id], ['user_id' => $counterTwo->id]],
        ])->assertOk()->assertJsonPath('status', 'counters_assigned');
        $this->assertCount(2, $assigned->json('data.assignments'));
        $this->postJson('/api/inventory/counts/'.$countId.'/assign-counters', ['assignments' => [['user_id' => $counterOne->id]]])->assertOk();
        $this->assertDatabaseHas('stock_count_assignments', ['stock_count_id' => $countId, 'user_id' => $counterTwo->id, 'status' => 'revoked']);
        $this->postJson('/api/inventory/counts/'.$countId.'/assign-counters', ['assignments' => [['user_id' => $counterOne->id], ['user_id' => $counterTwo->id]]])->assertOk();

        Sanctum::actingAs($checker, ['inventory:write', 'inventory:read']);
        $this->postJson('/api/inventory/counts/'.$countId.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'All assigned counters must complete their assignments before approval.');

        Sanctum::actingAs($counterOne, ['inventory:write', 'inventory:read']);
        $assignmentOne = $assigned->json('data.assignments.0.id');
        $this->postJson('/api/inventory/counts/'.$countId.'/assignments/'.$assignmentOne.'/complete')->assertOk();
        Sanctum::actingAs($counterTwo, ['inventory:write', 'inventory:read']);
        $assignmentTwo = $assigned->json('data.assignments.1.id');
        $this->postJson('/api/inventory/counts/'.$countId.'/assignments/'.$assignmentTwo.'/complete')->assertOk();

        Sanctum::actingAs($checker, ['inventory:write', 'inventory:read']);
        $this->postJson('/api/inventory/counts/'.$countId.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stock-count variance exceeds the configured 5% recount threshold; request an independent recount before approval.');
        $this->postJson('/api/inventory/counts/'.$countId.'/recount-request', ['recount_reason' => 'Policy threshold exceeded'])->assertOk();
        Sanctum::actingAs($counterOne, ['inventory:write', 'inventory:read']);
        $this->postJson('/api/inventory/counts/'.$countId.'/recount', ['lines' => [['product_id' => $product->id, 'counted_quantity' => 9]]])->assertOk();
        Sanctum::actingAs($checker, ['inventory:write', 'inventory:read']);
        $this->postJson('/api/inventory/counts/'.$countId.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseCount('stock_count_assignments', 2);
        $this->assertDatabaseHas('stock_count_assignments', ['stock_count_id' => $countId, 'status' => 'completed']);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'quantity' => 9]);
    }
}
