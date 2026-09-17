<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('customer_id')->constrained('inventory_locations')->nullOnDelete();
            $table->index(['company_id', 'location_id', 'status']);
        });
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->after('product_id')->constrained('inventory_locations')->nullOnDelete();
            $table->index(['company_id', 'location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'location_id', 'status']);
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'location_id', 'status']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['company_id']);
            $table->dropColumn('location_id');
            $table->dropColumn('company_id');
        });
    }
};
