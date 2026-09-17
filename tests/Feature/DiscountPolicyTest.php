<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\CompanyErpSetting;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\SalesQuotationLine;
use App\Models\Role;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscountPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_order_above_discount_policy_requires_override_permission(): void
    {
        $company = Company::create(['name' => 'Discount Policy Co', 'code' => 'DISCOUNT-POLICY']);
        CompanyErpSetting::create(['company_id' => $company->id, 'key' => 'max_discount_percent', 'value' => '10', 'value_type' => 'float']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Policy Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Policy Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Policy Each', 'code' => 'POLICY-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Policy Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Policy Item', 'status' => 1]);
        $order = SalesOrder::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'order_no' => 'DISCOUNT-POLICY-SO', 'date' => now()->toDateString(), 'status' => 'submitted', 'created_by' => $creator->id]);
        $order->lines()->create(['product_id' => $product->id, 'ordered_qty' => 1, 'unit_price' => 100, 'discount_amount' => 20]);
        Sanctum::actingAs($approver, ['sales:write']);

        $this->postJson('/api/integration/sales-orders/'.$order->id.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This sales order exceeds the maximum discount policy of 10%. Approval requires sales.discount.override permission.');

        $this->assertDatabaseHas('sales_orders', ['id' => $order->id, 'status' => 'submitted']);
    }

    public function test_sales_invoice_above_discount_policy_requires_override_permission(): void
    {
        $company = Company::create(['name' => 'Invoice Discount Policy Co', 'code' => 'INVOICE-DISCOUNT']);
        CompanyErpSetting::create(['company_id' => $company->id, 'key' => 'max_discount_percent', 'value' => '10', 'value_type' => 'float']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Invoice Policy Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Invoice Policy Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Invoice Policy Each', 'code' => 'INVOICE-POLICY-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Invoice Policy Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Invoice Policy Item', 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'invoice_no' => 'INVOICE-DISCOUNT-1', 'date' => now()->toDateString(), 'status' => 0, 'created_by' => $creator->id]);
        InvoiceDetail::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'category_id' => $category->id, 'date' => now()->toDateString(), 'selling_qty' => 1, 'unit_price' => 100, 'selling_price' => 100, 'status' => 0]);
        Payment::create(['company_id' => $company->id, 'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'paid_status' => 'full_due', 'paid_amount' => 0, 'due_amount' => 80, 'total_amount' => 80, 'discount_amount' => 20]);
        Sanctum::actingAs($approver, ['sales:write']);

        $this->postJson('/api/integration/sales-invoices/'.$invoice->id.'/approve')
            ->assertStatus(422)
            ->assertJsonPath('message', 'This sales document exceeds the maximum discount policy of 10%. Approval requires sales.discount.override permission.');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 0]);
    }

    public function test_sales_quotation_above_discount_policy_requires_override_permission(): void
    {
        $company = Company::create(['name' => 'Quotation Discount Policy Co', 'code' => 'QUOTATION-DISCOUNT']);
        CompanyErpSetting::create(['company_id' => $company->id, 'key' => 'max_discount_percent', 'value' => '10', 'value_type' => 'float']);
        $creator = User::factory()->create(['company_id' => $company->id]);
        $approver = User::factory()->create(['company_id' => $company->id]);
        $permission = Permission::create(['code' => 'sales.manage', 'name' => 'Manage sales', 'module' => 'sales']);
        $role = Role::create(['code' => 'quotation-sales-manager', 'name' => 'Quotation sales manager', 'is_active' => true]);
        $role->permissions()->attach($permission);
        $approver->roles()->attach($role);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Quotation Policy Customer', 'is_active' => true]);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Quotation Policy Supplier', 'is_active' => true]);
        $unit = Unit::create(['company_id' => $company->id, 'name' => 'Quotation Policy Each', 'code' => 'QUOTATION-POLICY-EA', 'dimension' => 'count', 'status' => 1, 'is_base' => true]);
        $category = Category::create(['company_id' => $company->id, 'name' => 'Quotation Policy Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Quotation Policy Item', 'status' => 1]);
        $quotation = SalesQuotation::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'quote_no' => 'QUOTATION-DISCOUNT-1', 'quote_date' => now()->toDateString(), 'status' => 'submitted', 'created_by' => $creator->id]);
        SalesQuotationLine::create(['sales_quotation_id' => $quotation->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'discount_amount' => 20]);

        $this->actingAs($approver)->post('/sales/quotations/'.$quotation->id.'/approve')
            ->assertRedirect()
            ->assertSessionHas('message', 'This sales document exceeds the maximum discount policy of 10%. Approval requires sales.discount.override permission.');

        $this->assertDatabaseHas('sales_quotations', ['id' => $quotation->id, 'status' => 'submitted']);
    }
}
