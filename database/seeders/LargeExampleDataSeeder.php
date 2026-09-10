<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LargeExampleDataSeeder extends Seeder
{
    private const RECORD_COUNT = 1000;

    /**
     * Create a large, repeatable dataset for local development and testing.
     *
     * This seeder owns records with the BULK-* / Bulk Demo prefixes only.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->removePreviousBulkData();

            $now = now();
            $adminId = DB::table('users')->where('email', 'demo.admin@example.com')->value('id');

            if (!$adminId) {
                $adminId = DB::table('users')->insertGetId([
                    'name' => 'Bulk Demo Admin',
                    'username' => 'bulk_demo_admin',
                    'email' => 'bulk.demo.admin@example.com',
                    'password' => Hash::make('BulkDemo@12345'),
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $suppliers = $this->insertSuppliers($now, $adminId);
            $units = $this->insertUnits($now, $adminId);
            $categories = $this->insertCategories($now, $adminId);
            $customers = $this->insertCustomers($now, $adminId);
            $products = $this->insertProducts($now, $adminId, $suppliers, $units, $categories);

            $this->insertPurchases($now, $adminId, $suppliers, $categories, $products);
            $invoices = $this->insertInvoices($now, $adminId, $customers);
            $this->insertInvoiceDetailsAndPayments($now, $adminId, $invoices, $customers, $categories, $products);
        });

        $this->command?->info('Created 1,000 linked bulk demo records for each major module.');
    }

    private function insertSuppliers($now, int $adminId): array
    {
        $rows = [];
        for ($i = 1; $i <= self::RECORD_COUNT; $i++) {
            $rows[] = [
                'name' => sprintf('Bulk Demo Supplier %04d', $i),
                'mobile_no' => '90000' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'email' => sprintf('bulk.supplier.%04d@example.com', $i),
                'address' => sprintf('Supplier Warehouse %04d', $i),
                'status' => 1,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('suppliers')->insert($rows);
        return DB::table('suppliers')->where('name', 'like', 'Bulk Demo Supplier %')->orderBy('id')->pluck('id')->all();
    }

    private function insertUnits($now, int $adminId): array
    {
        $rows = [];
        for ($i = 1; $i <= self::RECORD_COUNT; $i++) {
            $rows[] = [
                'name' => sprintf('Bulk Demo Unit %04d', $i),
                'status' => 1,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('units')->insert($rows);
        return DB::table('units')->where('name', 'like', 'Bulk Demo Unit %')->orderBy('id')->pluck('id')->all();
    }

    private function insertCategories($now, int $adminId): array
    {
        $rows = [];
        for ($i = 1; $i <= self::RECORD_COUNT; $i++) {
            $rows[] = [
                'name' => sprintf('Bulk Demo Category %04d', $i),
                'status' => 1,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('categories')->insert($rows);
        return DB::table('categories')->where('name', 'like', 'Bulk Demo Category %')->orderBy('id')->pluck('id')->all();
    }

    private function insertCustomers($now, int $adminId): array
    {
        $rows = [];
        for ($i = 1; $i <= self::RECORD_COUNT; $i++) {
            $rows[] = [
                'name' => sprintf('Bulk Demo Customer %04d', $i),
                'mobile_no' => '91000' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'email' => sprintf('bulk.customer.%04d@example.com', $i),
                'address' => sprintf('Customer Address %04d', $i),
                'status' => 1,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('customers')->insert($rows);
        return DB::table('customers')->where('name', 'like', 'Bulk Demo Customer %')->orderBy('id')->pluck('id')->all();
    }

    private function insertProducts($now, int $adminId, array $suppliers, array $units, array $categories): array
    {
        $rows = [];
        for ($i = 0; $i < self::RECORD_COUNT; $i++) {
            $rows[] = [
                'supplier_id' => $suppliers[$i],
                'unit_id' => $units[$i],
                'category_id' => $categories[$i],
                'name' => sprintf('Bulk Demo Product %04d', $i + 1),
                // Even-numbered products are used by approved invoices and lose 10 units.
                'quantity' => ($i % 2 === 0) ? 90 : 100,
                'status' => 1,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('products')->insert($rows);
        return DB::table('products')->where('name', 'like', 'Bulk Demo Product %')->orderBy('id')->pluck('id')->all();
    }

    private function insertPurchases($now, int $adminId, array $suppliers, array $categories, array $products): void
    {
        $rows = [];
        for ($i = 0; $i < self::RECORD_COUNT; $i++) {
            $unitPrice = 10 + (($i % 50) * 2);
            $rows[] = [
                'supplier_id' => $suppliers[$i],
                'category_id' => $categories[$i],
                'product_id' => $products[$i],
                'purchase_no' => sprintf('BULK-PUR-%04d', $i + 1),
                'date' => now()->subDays($i % 30)->toDateString(),
                'description' => 'Bulk approved purchase example',
                'buying_qty' => 100,
                'unit_price' => $unitPrice,
                'buying_price' => 100 * $unitPrice,
                'status' => 1,
                'created_by' => $adminId,
                'updated_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('purchases')->insert($rows);
    }

    private function insertInvoices($now, int $adminId, array $customers): array
    {
        $rows = [];
        for ($i = 0; $i < self::RECORD_COUNT; $i++) {
            $rows[] = [
                // Numeric invoice numbers remain compatible with invoiceAdd's next-number logic.
                'invoice_no' => sprintf('%06d', 200001 + $i),
                'date' => now()->subDays($i % 30)->toDateString(),
                'description' => ($i % 2 === 0) ? 'Bulk demo invoice - approved' : 'Bulk demo invoice - pending',
                'status' => ($i % 2 === 0) ? 1 : 0,
                'created_by' => $adminId,
                'updated_by' => ($i % 2 === 0) ? $adminId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('invoices')->insert($rows);
        return DB::table('invoices')->where('invoice_no', 'like', 'BULK-INV-%')->orderBy('id')->get(['id', 'invoice_no', 'date', 'status'])->all();
    }

    private function insertInvoiceDetailsAndPayments($now, int $adminId, array $invoices, array $customers, array $categories, array $products): void
    {
        $detailRows = [];
        $paymentRows = [];
        $paymentDetailRows = [];

        foreach ($invoices as $i => $invoice) {
            $quantity = 10;
            $unitPrice = 20 + (($i % 50) * 3);
            $total = $quantity * $unitPrice;
            $isPaid = $i % 3 === 0;
            $isDue = $i % 3 === 1;
            $paid = $isPaid ? $total : ($isDue ? 0 : (int) ($total / 2));
            $status = $isPaid ? 'full_paid' : ($isDue ? 'full_due' : 'partial_paid');

            $detailRows[] = [
                'date' => $invoice->date,
                'invoice_id' => $invoice->id,
                'category_id' => $categories[$i],
                'product_id' => $products[$i],
                'selling_qty' => $quantity,
                'unit_price' => $unitPrice,
                'selling_price' => $total,
                'status' => $invoice->status,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $paymentRows[] = [
                'invoice_id' => $invoice->id,
                'customer_id' => $customers[$i],
                'paid_status' => $status,
                'paid_amount' => $paid,
                'due_amount' => $total - $paid,
                'total_amount' => $total,
                'discount_amount' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $paymentDetailRows[] = [
                'invoice_id' => $invoice->id,
                'current_paid_amount' => $paid,
                'date' => $invoice->date,
                'updated_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('invoice_details')->insert($detailRows);
        DB::table('payments')->insert($paymentRows);
        DB::table('payment_details')->insert($paymentDetailRows);
    }

    private function removePreviousBulkData(): void
    {
        $invoiceIds = DB::table('invoices')->where('description', 'like', 'Bulk demo invoice -%')->pluck('id');
        if ($invoiceIds->isNotEmpty()) {
            DB::table('payment_details')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('payments')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoice_details')->whereIn('invoice_id', $invoiceIds)->delete();
            DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
        }

        DB::table('purchases')->where('purchase_no', 'like', 'BULK-PUR-%')->delete();
        DB::table('products')->where('name', 'like', 'Bulk Demo Product %')->delete();
        DB::table('customers')->where('name', 'like', 'Bulk Demo Customer %')->delete();
        DB::table('suppliers')->where('name', 'like', 'Bulk Demo Supplier %')->delete();
        DB::table('units')->where('name', 'like', 'Bulk Demo Unit %')->delete();
        DB::table('categories')->where('name', 'like', 'Bulk Demo Category %')->delete();
    }
}
