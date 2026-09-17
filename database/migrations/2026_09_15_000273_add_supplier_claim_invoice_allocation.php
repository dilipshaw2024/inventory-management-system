<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->foreignId('purchase_invoice_id')->nullable()->after('supplier_id')->constrained('purchase_invoices')->nullOnDelete();
            $table->index(['company_id', 'purchase_invoice_id', 'status'], 'supplier_claims_invoice_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->dropIndex('supplier_claims_invoice_status_idx');
            $table->dropForeign(['purchase_invoice_id']);
            $table->dropColumn('purchase_invoice_id');
        });
    }
};
