<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_product_prices', 'customer_product_prices', 'promotions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['company_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        foreach (['supplier_product_prices', 'customer_product_prices', 'promotions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex([$tableName.'_company_id_is_active_index']);
                $table->dropColumn('company_id');
            });
        }
    }
};
