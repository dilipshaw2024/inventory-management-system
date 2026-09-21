<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ServiceAsset;
use App\Models\ServiceContract;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceContractWarrantyClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_warranty_claim_can_snapshot_active_contract_coverage(): void
    {
        $company = Company::create(['name' => 'Contract Warranty Co', 'code' => 'CONTRACT-WARRANTY']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Contract Customer', 'status' => 1]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Contract Vendor', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Contract Unit', 'status' => 1]);
        $category = Category::create(['name' => 'Contract Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'name' => 'Contract Product', 'unit_id' => $unit->id, 'category_id' => $category->id, 'status' => 1]);
        $asset = ServiceAsset::create(['company_id' => $company->id, 'asset_no' => 'ASSET-CONTRACT-1', 'name' => 'Contract Asset', 'product_id' => $product->id, 'customer_id' => $customer->id, 'status' => 'active']);
        $contract = ServiceContract::create(['company_id' => $company->id, 'contract_no' => 'SC-CONTRACT-1', 'customer_id' => $customer->id, 'asset_id' => $asset->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'coverage_type' => 'full', 'status' => 'active']);

        Sanctum::actingAs($user, ['service:read', 'service:write']);
        $claim = $this->postJson('/api/service/warranty-claims', ['asset_id' => $asset->id, 'contract_id' => $contract->id, 'supplier_id' => $supplier->id, 'received_at' => '2026-09-21', 'issue' => 'Covered service issue.'])
            ->assertCreated()->assertJsonPath('data.contract_id', $contract->id)->assertJsonPath('data.supplier_id', $supplier->id)->assertJsonPath('data.coverage_status', 'contract_covered');
        $claimId = $claim->json('data.id');
        $this->patchJson('/api/service/warranty-claims/'.$claimId, ['status' => 'approved'])->assertStatus(422);
        $this->patchJson('/api/service/warranty-claims/'.$claimId, ['status' => 'approved', 'covered' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson('/api/service/warranty-claims/'.$claimId.'/settle', ['settlement_reference' => 'WARRANTY-SETTLE-1', 'settlement_amount' => 125.50, 'settlement_currency' => 'usd', 'accounting_mode' => 'vendor_recovery'])->assertOk()->assertJsonPath('status', 'settled')->assertJsonPath('data.settlement_currency', 'USD')->assertJsonPath('data.accounting_status', 'missing_mapping');
        $this->postJson('/api/service/warranty-claims/'.$claimId.'/settle', ['settlement_reference' => 'WARRANTY-SETTLE-1', 'settlement_amount' => 125.50, 'settlement_currency' => 'USD'])->assertOk()->assertJsonPath('status', 'duplicate_ignored');
        $this->postJson('/api/service/warranty-claims/'.$claimId.'/post-accounting', ['accounting_mode' => 'customer_reimbursement'])->assertOk()->assertJsonPath('status', 'missing_mapping')->assertJsonPath('data.accounting_status', 'missing_mapping');
        $this->getJson('/api/service/warranty-claims?contract_id='.$contract->id.'&coverage_status=contract_covered&settlement_status=settled&accounting_status=missing_mapping')
            ->assertOk()->assertJsonPath('data.0.contract_id', $contract->id);
        $this->assertDatabaseHas('warranty_claims', ['asset_id' => $asset->id, 'contract_id' => $contract->id, 'coverage_status' => 'contract_covered', 'settlement_status' => 'settled', 'settlement_reference' => 'WARRANTY-SETTLE-1']);
    }
}
