<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropForeign(['purchase_invoice_id']);
            $table->index(['supplier_id', 'status']);
        });
        DB::statement('ALTER TABLE supplier_payments MODIFY purchase_invoice_id BIGINT UNSIGNED NULL');
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->restrictOnDelete();
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->decimal('amount', 19, 6);
            $table->string('external_reference')->nullable();
            $table->timestamp('allocated_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['supplier_payment_id', 'purchase_invoice_id'], 'supplier_payment_invoice_unique');
            $table->unique(['company_id', 'external_reference'], 'supplier_allocations_company_external_unique');
            $table->index(['company_id', 'purchase_invoice_id'], 'supplier_allocations_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropIndex(['supplier_id', 'status']);
            $table->dropForeign(['purchase_invoice_id']);
        });
        DB::statement('ALTER TABLE supplier_payments MODIFY purchase_invoice_id BIGINT UNSIGNED NOT NULL');
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->foreign('purchase_invoice_id')->references('id')->on('purchase_invoices')->restrictOnDelete();
        });
    }
};
