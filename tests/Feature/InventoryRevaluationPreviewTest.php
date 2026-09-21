<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\InventoryCostLayer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryRevaluationPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_revaluation_preview_reports_standard_cost_variance_without_mutation(): void
    {
        $company = Company::create(['name' => 'Revaluation Co', 'code' => 'REVAL-TEST']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Revaluation Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Revaluation Each', 'status' => 1]);
        $category = Category::create(['name' => 'Revaluation Category', 'status' => 1]);
        $product = Product::create([
            'company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id,
            'name' => 'Revaluation item', 'sku' => 'REVAL-ITEM', 'status' => 1,
            'costing_method' => 'standard', 'standard_cost' => 8, 'quantity' => 5,
        ]);
        $layer = InventoryCostLayer::create([
            'product_id' => $product->id, 'original_quantity' => 5, 'remaining_quantity' => 5,
            'unit_cost' => 6, 'received_at' => now()->subDay(),
        ]);

        Sanctum::actingAs($user, ['inventory:read']);
        $response = $this->getJson('/api/inventory/valuation/revaluation-preview?product_id='.$product->id);

        $response->assertOk()
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.current_value', 30)
            ->assertJsonPath('data.0.target_value', 40)
            ->assertJsonPath('data.0.variance_amount', 10)
            ->assertJsonPath('data.0.revaluation_required', true)
            ->assertJsonPath('data.0.lines.0.layer_id', $layer->id);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 6]);
    }

    public function test_revaluation_run_requires_independent_approval_and_updates_open_layer(): void
    {
        $company = Company::create(['name' => 'Revaluation Approval Co', 'code' => 'REVAL-APPROVAL']);
        $requester = User::factory()->create(['company_id' => $company->id]);
        $checker = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Approval Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Approval Each', 'status' => 1]);
        $category = Category::create(['name' => 'Approval Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Approval item', 'status' => 1, 'costing_method' => 'standard', 'standard_cost' => 10, 'quantity' => 3]);
        $layer = InventoryCostLayer::create(['product_id' => $product->id, 'original_quantity' => 3, 'remaining_quantity' => 3, 'unit_cost' => 7, 'received_at' => now()]);

        Sanctum::actingAs($requester, ['inventory:write']);
        $created = $this->postJson('/api/inventory/valuation/revaluations', ['external_reference' => 'REVAL-RUN-1']);
        $created->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('data.status', 'pending');
        $runId = $created->json('data.id');
        $this->postJson('/api/inventory/valuation/revaluations/'.$runId.'/approve')->assertStatus(422);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 7]);

        Sanctum::actingAs($checker, ['inventory:write']);
        $this->postJson('/api/inventory/valuation/revaluations/'.$runId.'/approve')->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('data.accounting_status', 'missing_mapping');
        $this->assertDatabaseHas('inventory_cost_revaluation_runs', ['id' => $runId, 'status' => 'approved', 'accounting_status' => 'missing_mapping']);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 10]);
        $this->postJson('/api/inventory/valuation/revaluations', ['external_reference' => 'REVAL-RUN-1'])->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        Sanctum::actingAs($requester, ['inventory:write']);
        $this->postJson('/api/inventory/valuation/revaluations/'.$runId.'/reverse', ['reversal_reason' => 'Correction required'])->assertOk()->assertJsonPath('status', 'reversed');
        $this->assertDatabaseHas('inventory_cost_revaluation_runs', ['id' => $runId, 'status' => 'reversed', 'reversal_reason' => 'Correction required']);
        $this->assertDatabaseHas('inventory_cost_layers', ['id' => $layer->id, 'unit_cost' => 7]);
    }
}
