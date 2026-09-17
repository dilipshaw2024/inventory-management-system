<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders', 'invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('store_id')->nullable()->after('company_id')->constrained('stores')->nullOnDelete();
                $table->index(['company_id', 'store_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_orders', 'invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['store_id']);
                $table->dropIndex([$tableName.'_company_id_store_id_index']);
                $table->dropColumn('store_id');
            });
        }
    }
};
