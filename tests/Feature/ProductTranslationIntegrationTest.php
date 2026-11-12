<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductTranslation;
use App\Models\TaxRate;
use App\Models\TaxRateComponent;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductTranslationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_translations_are_tenant_scoped_idempotent_and_manageable(): void
    {
        $company = Company::create(['name' => 'Translation Co', 'code' => 'TRANSLATION']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = $this->product($company);
        Sanctum::actingAs($user, ['inventory:read', 'inventory:write']);

        $payload = ['language_code' => 'pt_br', 'name' => 'Produto local', 'short_description' => 'Descrição curta', 'external_reference' => 'PRODUCT-PT-BR'];
        $created = $this->postJson('/api/inventory/products/'.$product->id.'/translations', $payload)
            ->assertCreated()->assertJsonPath('data.language_code', 'pt-BR')->assertJsonPath('data.name', 'Produto local');
        $translationId = $created->json('data.id');
        $this->postJson('/api/inventory/products/'.$product->id.'/translations', $payload)
            ->assertOk()->assertJsonPath('status', 'duplicate_ignored')->assertJsonPath('data.id', $translationId);
        $this->patchJson('/api/inventory/products/'.$product->id.'/translations/'.$translationId, ['name' => 'Produto atualizado'])
            ->assertOk()->assertJsonPath('data.name', 'Produto atualizado');
        $this->postJson('/api/inventory/products/'.$product->id.'/translations/'.$translationId.'/deactivate')
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('product_translations', ['id' => $translationId, 'company_id' => $company->id, 'language_code' => 'pt-BR', 'is_active' => 0]);
    }

    public function test_product_feed_exposes_requested_translation_and_falls_back_to_base_name(): void
    {
        $company = Company::create(['name' => 'Translation Feed Co', 'code' => 'TRANSLATION-FEED']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $product = $this->product($company, 'Base product');
        $taxRate = TaxRate::create(['company_id' => $company->id, 'code' => 'VAT18', 'name' => 'VAT 18', 'rate' => 18, 'calculation' => 'exclusive', 'jurisdiction' => 'DOMESTIC', 'effective_from' => '2026-01-01', 'is_active' => true]);
        TaxRateComponent::create(['company_id' => $company->id, 'tax_rate_id' => $taxRate->id, 'code' => 'VAT', 'name' => 'VAT', 'rate' => 18, 'component_type' => 'central', 'sequence' => 1, 'is_active' => true]);
        $product->update(['tax_rate_id' => $taxRate->id, 'tax_rate' => 18]);
        ProductTranslation::create(['company_id' => $company->id, 'product_id' => $product->id, 'language_code' => 'fr-FR', 'name' => 'Produit français', 'is_active' => true]);
        Sanctum::actingAs($user, ['inventory:read']);

        $this->getJson('/api/inventory/products?language_code=fr_fr')->assertOk()
            ->assertJsonPath('data.0.translations.0.language_code', 'fr-FR')->assertJsonPath('data.0.translations.0.name', 'Produit français')
            ->assertJsonPath('data.0.tax_definition.code', 'VAT18')->assertJsonPath('data.0.tax_definition.components.0.code', 'VAT');
        $this->assertSame('Produit français', $product->fresh()->localizedName('fr-FR', $company->id));
        $this->assertSame('Base product', $product->fresh()->localizedName('de-DE', $company->id));
    }

    private function product(Company $company, string $name = 'Base product'): Product
    {
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Translation Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Translation Each', 'code' => 'TRAN-EA', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Translation Category', 'status' => 1]);
        return Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => $name, 'status' => 1]);
    }
}
