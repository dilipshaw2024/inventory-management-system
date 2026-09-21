<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NegativeStockExceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_negative_stock_exception_feed_reports_legacy_balance(): void
    {
        $company = Company::create(['name' => 'Negative Exception Co', 'code' => 'NEGATIVE-EX']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Negative Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Each', 'code' => 'EA-NEGATIVE', 'dimension' => 'unit', 'status' => 1]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Negative Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Negative item', 'quantity' => -2, 'status' => 1]);

        Sanctum::actingAs($user, ['inventory:read']);
        $this->getJson('/api/inventory/stock/exceptions/negative')->assertOk()
            ->assertJsonPath('summary.exception_count', 1)
            ->assertJsonPath('summary.legacy_exception_count', 1)
            ->assertJsonPath('data.0.product_id', $product->id)
            ->assertJsonPath('data.0.legacy_balance', -2)
            ->assertJsonPath('data.0.source', 'legacy_product_balance');
    }
}
