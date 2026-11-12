<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('sales_channel', 50)->nullable()->after('store_id');
            $table->string('delivery_method', 30)->nullable()->after('sales_channel');
            $table->foreignId('salesperson_id')->nullable()->after('delivery_method')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'sales_channel', 'delivery_method'], 'sales_orders_sales_dimensions_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('sales_orders_sales_dimensions_idx');
            $table->dropForeign(['salesperson_id']);
            $table->dropColumn(['sales_channel', 'delivery_method', 'salesperson_id']);
        });
    }
};
