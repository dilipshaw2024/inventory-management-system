<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('price_list_id')->nullable()->after('customer_id')->constrained('price_lists')->nullOnDelete();
            $table->index(['company_id', 'price_list_id'], 'sales_orders_company_price_list_idx');
        });
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->foreignId('price_list_id')->nullable()->after('supplier_id')->constrained('price_lists')->nullOnDelete();
            $table->index(['company_id', 'price_list_id'], 'purchase_orders_company_price_list_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropIndex('purchase_orders_company_price_list_idx');
            $table->dropForeign(['price_list_id']);
            $table->dropColumn('price_list_id');
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('sales_orders_company_price_list_idx');
            $table->dropForeign(['price_list_id']);
            $table->dropColumn('price_list_id');
        });
    }
};
