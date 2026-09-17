<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchases', 'invoices', 'purchase_orders', 'sales_orders', 'inventory_adjustments', 'inventory_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index('company_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['purchases', 'invoices', 'purchase_orders', 'sales_orders', 'inventory_adjustments', 'inventory_transfers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex([$tableName.'_company_id_index']);
                $table->dropColumn('company_id');
            });
        }
    }
};
