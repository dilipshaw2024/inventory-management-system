<?php

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\Category;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\InventoryMovement;
use App\Models\InventoryMovementAllocation;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\InventoryCostLayer;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillInventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_costing_and_accounting_backfill_uses_legacy_transaction_dates(): void
    {
        $company = Company::create(['name' => 'Backfill Co', 'code' => 'BACKFILL-TEST']);
        $supplier = Supplier::create(['company_id' => $company->id, 'name' => 'Backfill Supplier', 'is_active' => true]);
        $unit = Unit::create(['name' => 'Backfill Each', 'status' => 1]);
        $category = Category::create(['name' => 'Backfill Category', 'status' => 1]);
        $product = Product::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'unit_id' => $unit->id, 'category_id' => $category->id, 'name' => 'Backfill item', 'sku' => 'BACKFILL-ITEM', 'status' => 1]);
        $year = FiscalYear::create(['company_id' => $company->id, 'name' => 'FY BACKFILL', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::create(['company_id' => $company->id, 'fiscal_year_id' => $year->id, 'name' => '2026-09', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'open']);

        foreach ([
            'inventory' => ['code' => 'BF-1300', 'name' => 'Inventory', 'account_type' => 'asset'],
            'grni' => ['code' => 'BF-2100', 'name' => 'GRNI', 'account_type' => 'liability'],
            'cogs' => ['code' => 'BF-5100', 'name' => 'COGS', 'account_type' => 'expense'],
        ] as $mapping => $account) {
            $accountModel = ChartOfAccount::create(['company_id' => $company->id] + $account);
            AccountMapping::create(['company_id' => $company->id, 'mapping_key' => $mapping, 'account_id' => $accountModel->id]);
        }

        $purchase = Purchase::create(['company_id' => $company->id, 'supplier_id' => $supplier->id, 'category_id' => $category->id, 'product_id' => $product->id, 'purchase_no' => 'BF-PUR-1', 'date' => '2026-09-05', 'buying_qty' => 5, 'unit_price' => 10, 'buying_price' => 50, 'status' => 1]);
        $invoice = Invoice::create(['company_id' => $company->id, 'invoice_no' => 'BF-INV-1', 'date' => '2026-09-06', 'status' => 1]);
        $detail = InvoiceDetail::create(['date' => '2026-09-06', 'invoice_id' => $invoice->id, 'category_id' => $category->id, 'product_id' => $product->id, 'selling_qty' => 2, 'unit_price' => 20, 'selling_price' => 40, 'status' => 1]);

        $exitCode = Artisan::call('erp:backfill-inventory-ledger', ['--with-costing' => true, '--with-accounting' => true]);

        $this->assertSame(0, $exitCode);
        $receipt = InventoryMovement::where('reference_type', $purchase->getMorphClass())->where('reference_id', $purchase->id)->firstOrFail();
        $issue = InventoryMovement::where('reference_type', $detail->getMorphClass())->where('reference_id', $detail->id)->firstOrFail();
        $this->assertSame('2026-09-05', $receipt->posted_at->toDateString());
        $this->assertSame('2026-09-06', $issue->posted_at->toDateString());
        $this->assertSame(1, InventoryCostLayer::where('product_id', $product->id)->count());
        $this->assertDatabaseHas('inventory_cost_layers', ['product_id' => $product->id, 'original_quantity' => '5.000000', 'remaining_quantity' => '3.000000', 'unit_cost' => '10.000000']);
        $this->assertDatabaseHas('inventory_movement_allocations', ['movement_id' => $issue->id, 'quantity' => '2.000000', 'unit_cost' => '10.000000']);
        $this->assertSame(2, JournalEntry::where('company_id', $company->id)->where('status', 'posted')->count());
        $this->assertSame(1, InventoryMovementAllocation::where('movement_id', $issue->id)->count());
    }
}
