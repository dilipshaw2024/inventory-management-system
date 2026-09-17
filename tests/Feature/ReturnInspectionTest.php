<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InventoryReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_inspection_must_pass_before_approval(): void
    {
        $company = Company::create(['name' => 'Inspection Co', 'code' => 'RETURN-INSPECTION']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $inspector = User::factory()->create(['company_id' => $company->id]);
        $return = InventoryReturn::create(['company_id' => $company->id, 'return_no' => 'RET-INSPECT-1', 'return_type' => 'sales', 'date' => now()->toDateString(), 'reason_code' => 'quality', 'inspection_required' => true, 'inspection_status' => 'pending', 'status' => 'pending', 'created_by' => $creator->id]);
        Sanctum::actingAs($inspector, ['sales:write']);

        $this->postJson('/api/integration/returns/'.$return->id.'/approve')->assertStatus(422)->assertJsonPath('message', 'This return must pass inspection before approval.');
        $this->postJson('/api/integration/returns/'.$return->id.'/inspect', ['inspection_status' => 'passed', 'inspection_notes' => 'Items inspected and accepted.'])->assertOk()->assertJsonPath('status', 'passed');
        $this->assertDatabaseHas('inventory_returns', ['id' => $return->id, 'inspection_status' => 'passed', 'inspected_by' => $inspector->id]);
    }
}
