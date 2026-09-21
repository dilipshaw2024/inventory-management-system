<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->after('customer_id')->constrained('suppliers')->nullOnDelete();
            $table->index(['company_id', 'supplier_id', 'settlement_status'], 'warranty_claims_supplier_settlement_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropIndex('warranty_claims_supplier_settlement_idx');
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
