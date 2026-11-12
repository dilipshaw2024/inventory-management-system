<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_report_export_supports_xlsx_with_company_scoped_rows(): void
    {
        $company = Company::create(['name' => 'Sales XLSX Co', 'code' => 'SALES-XLSX']);
        $user = User::factory()->create(['company_id' => $company->id]);
        $permissions = collect(['reports.view', 'reports.export'])->map(fn (string $code): Permission => Permission::create(['code' => $code, 'name' => $code, 'module' => 'reports']));
        $role = Role::create(['code' => 'sales-xlsx-exporter', 'name' => 'Sales XLSX exporter', 'is_active' => true]);
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Sales report supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Each', 'status' => 1]);
        $category = Category::create(['name' => 'Sales report category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Sales XLSX item', 'quantity' => 0, 'status' => 1]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Sales XLSX customer', 'sales_channel' => 'online', 'is_active' => true]);
        $invoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'created_by' => $user->id, 'salesperson_id' => $user->id, 'sales_channel' => 'online', 'delivery_method' => 'delivery', 'invoice_no' => 'SALES-XLSX-1', 'date' => '2026-09-18', 'status' => 1, 'subtotal_amount' => 25, 'tax_amount' => 0, 'total_amount' => 25]);
        InvoiceDetail::create(['date' => '2026-09-18', 'invoice_id' => $invoice->id, 'category_id' => $category->id, 'product_id' => $product->id, 'selling_qty' => 1, 'unit_price' => 25, 'selling_price' => 25, 'tax_rate' => 0, 'tax_amount' => 0, 'status' => 1]);
        $priorInvoice = Invoice::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'created_by' => $user->id, 'salesperson_id' => $user->id, 'sales_channel' => 'online', 'delivery_method' => 'delivery', 'invoice_no' => 'SALES-XLSX-PRIOR', 'date' => '2026-08-18', 'status' => 1, 'subtotal_amount' => 10, 'tax_amount' => 0, 'total_amount' => 10]);
        InvoiceDetail::create(['date' => '2026-08-18', 'invoice_id' => $priorInvoice->id, 'category_id' => $category->id, 'product_id' => $product->id, 'selling_qty' => 1, 'unit_price' => 10, 'selling_price' => 10, 'tax_rate' => 0, 'tax_amount' => 0, 'status' => 1]);

        $comparison = $this->actingAs($user)->get('/reports/sales?from=2026-09-01&to=2026-09-30&compare_from=2026-08-01&compare_to=2026-08-31&sales_channel=online');
        $comparison->assertOk()->assertSee('Period comparison')->assertSee('15.00');

        $response = $this->actingAs($user)->get('/reports/sales/export?from=2026-09-01&to=2026-09-30&format=xlsx');
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $response->getContent());
        $this->assertStringContainsString('sales-report-2026-09-01-to-2026-09-30.xlsx', $response->headers->get('content-disposition'));

        $csvResponse = $this->actingAs($user)->get('/reports/sales/export?from=2026-09-01&to=2026-09-30&sales_channel=online&salesperson_id='.$user->id);
        $csvResponse->assertOk();
        self::assertStringStartsWith('text/csv', (string) $csvResponse->headers->get('content-type'));
        $this->assertStringContainsString('online', $csvResponse->streamedContent());
    }
}
