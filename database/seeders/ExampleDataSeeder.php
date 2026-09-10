<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceDetail;
use App\Models\Payment;
use App\Models\PaymentDetail;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ExampleDataSeeder extends Seeder
{
    /**
     * Seed repeatable demo data for local development and demonstrations.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $admin = User::updateOrCreate(
                ['email' => 'demo.admin@example.com'],
                [
                    'name' => 'Demo Admin',
                    'username' => 'demo_admin',
                    'password' => Hash::make('Demo@12345'),
                    'email_verified_at' => now(),
                ]
            );

            $supplier = Supplier::updateOrCreate(
                ['name' => 'Demo Wholesale Supplier'],
                [
                    'mobile_no' => '9876543210',
                    'email' => 'supplier@example.com',
                    'address' => '12 Market Road',
                    'status' => 1,
                    'created_by' => $admin->id,
                ]
            );

            $secondSupplier = Supplier::updateOrCreate(
                ['name' => 'Demo Fresh Goods Supplier'],
                [
                    'mobile_no' => '9876543211',
                    'email' => 'fresh.supplier@example.com',
                    'address' => '24 Warehouse Road',
                    'status' => 1,
                    'created_by' => $admin->id,
                ]
            );

            $piece = Unit::updateOrCreate(['name' => 'Piece'], ['status' => 1, 'created_by' => $admin->id]);
            $kilogram = Unit::updateOrCreate(['name' => 'Kilogram'], ['status' => 1, 'created_by' => $admin->id]);

            $electronics = Category::updateOrCreate(['name' => 'Demo Electronics'], ['status' => 1, 'created_by' => $admin->id]);
            $grocery = Category::updateOrCreate(['name' => 'Demo Grocery'], ['status' => 1, 'created_by' => $admin->id]);

            $phone = Product::updateOrCreate(
                ['name' => 'Demo Smartphone'],
                [
                    'supplier_id' => $supplier->id,
                    'unit_id' => $piece->id,
                    'category_id' => $electronics->id,
                    'quantity' => 42,
                    'status' => 1,
                    'created_by' => $admin->id,
                ]
            );

            $headphones = Product::updateOrCreate(
                ['name' => 'Demo Wireless Headphones'],
                [
                    'supplier_id' => $supplier->id,
                    'unit_id' => $piece->id,
                    'category_id' => $electronics->id,
                    'quantity' => 25,
                    'status' => 1,
                    'created_by' => $admin->id,
                ]
            );

            $rice = Product::updateOrCreate(
                ['name' => 'Demo Rice'],
                [
                    'supplier_id' => $secondSupplier->id,
                    'unit_id' => $kilogram->id,
                    'category_id' => $grocery->id,
                    'quantity' => 20,
                    'status' => 1,
                    'created_by' => $admin->id,
                ]
            );

            $customer = Customer::updateOrCreate(
                ['email' => 'customer@example.com'],
                [
                    'name' => 'Demo Customer',
                    'mobile_no' => '9123456780',
                    'address' => '45 Customer Street',
                    'status' => 1,
                    'created_by' => $admin->id,
                ]
            );

            $creditCustomer = Customer::updateOrCreate(
                ['email' => 'credit.customer@example.com'],
                [
                    'name' => 'Demo Credit Customer',
                    'mobile_no' => '9123456781',
                    'address' => '78 Customer Street',
                    'status' => 1,
                    'created_by' => $admin->id,
                ]
            );

            Purchase::updateOrCreate(
                ['purchase_no' => 'DEMO-PUR-001'],
                [
                    'supplier_id' => $supplier->id,
                    'category_id' => $electronics->id,
                    'product_id' => $phone->id,
                    'date' => now()->subDays(3)->toDateString(),
                    'description' => 'Approved demo smartphone purchase',
                    'buying_qty' => 50,
                    'unit_price' => 250,
                    'buying_price' => 12500,
                    'status' => 1,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );

            Purchase::updateOrCreate(
                ['purchase_no' => 'DEMO-PUR-002'],
                [
                    'supplier_id' => $secondSupplier->id,
                    'category_id' => $grocery->id,
                    'product_id' => $rice->id,
                    'date' => now()->subDay()->toDateString(),
                    'description' => 'Pending demo purchase for approval practice',
                    'buying_qty' => 10,
                    'unit_price' => 30,
                    'buying_price' => 300,
                    'status' => 0,
                    'created_by' => $admin->id,
                ]
            );

            Purchase::updateOrCreate(
                ['purchase_no' => 'DEMO-PUR-003'],
                [
                    'supplier_id' => $supplier->id,
                    'category_id' => $electronics->id,
                    'product_id' => $headphones->id,
                    'date' => now()->subDays(3)->toDateString(),
                    'description' => 'Approved demo headphones purchase',
                    'buying_qty' => 30,
                    'unit_price' => 40,
                    'buying_price' => 1200,
                    'status' => 1,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );

            Purchase::updateOrCreate(
                ['purchase_no' => 'DEMO-PUR-004'],
                [
                    'supplier_id' => $secondSupplier->id,
                    'category_id' => $grocery->id,
                    'product_id' => $rice->id,
                    'date' => now()->subDays(3)->toDateString(),
                    'description' => 'Approved demo rice purchase',
                    'buying_qty' => 20,
                    'unit_price' => 25,
                    'buying_price' => 500,
                    'status' => 1,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );

            $approvedInvoice = Invoice::updateOrCreate(
                ['invoice_no' => '1001'],
                [
                    'date' => now()->subDays(2)->toDateString(),
                    'description' => 'Approved demo sale',
                    'status' => 1,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );

            $this->replaceInvoiceDetails($approvedInvoice, [
                ['category_id' => $electronics->id, 'product_id' => $phone->id, 'selling_qty' => 8, 'unit_price' => 400, 'selling_price' => 3200],
                ['category_id' => $electronics->id, 'product_id' => $headphones->id, 'selling_qty' => 5, 'unit_price' => 80, 'selling_price' => 400],
            ], $admin->id, true);
            $this->replacePayment($approvedInvoice, $customer, 'full_paid', 3600, 0, 3600, 0, $admin->id);

            $pendingInvoice = Invoice::updateOrCreate(
                ['invoice_no' => '1002'],
                [
                    'date' => now()->toDateString(),
                    'description' => 'Pending demo sale for approval practice',
                    'status' => 0,
                    'created_by' => $admin->id,
                ]
            );

            $this->replaceInvoiceDetails($pendingInvoice, [
                ['category_id' => $grocery->id, 'product_id' => $rice->id, 'selling_qty' => 4, 'unit_price' => 45, 'selling_price' => 180],
            ], $admin->id, false);
            $this->replacePayment($pendingInvoice, $creditCustomer, 'partial_paid', 80, 100, 180, 0, $admin->id);
        });
    }

    private function replaceInvoiceDetails(Invoice $invoice, array $lines, int $adminId, bool $approved): void
    {
        InvoiceDetail::where('invoice_id', $invoice->id)->delete();

        foreach ($lines as $line) {
            InvoiceDetail::create(array_merge($line, [
                'invoice_id' => $invoice->id,
                'date' => $invoice->date,
                'status' => $approved ? 1 : 0,
            ]));
        }
    }

    private function replacePayment(
        Invoice $invoice,
        Customer $customer,
        string $status,
        float $paid,
        float $due,
        float $total,
        float $discount,
        int $adminId
    ): void {
        $payment = Payment::updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'customer_id' => $customer->id,
                'paid_status' => $status,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'total_amount' => $total,
                'discount_amount' => $discount,
            ]
        );

        PaymentDetail::where('invoice_id', $invoice->id)->delete();
        PaymentDetail::create([
            'invoice_id' => $invoice->id,
            'current_paid_amount' => $paid,
            'date' => $invoice->date,
            'updated_by' => $adminId,
        ]);
    }
}
