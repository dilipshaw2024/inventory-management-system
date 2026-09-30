<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('fulfillment_priority')->default(50)->after('allow_backorders');
            $table->index(['company_id', 'status', 'allow_backorders', 'fulfillment_priority', 'requested_date'], 'sales_orders_backorder_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex('sales_orders_backorder_priority_idx');
            $table->dropColumn('fulfillment_priority');
        });
    }
};
