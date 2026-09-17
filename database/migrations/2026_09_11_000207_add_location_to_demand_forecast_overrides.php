<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demand_forecast_overrides', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('product_id')->constrained('inventory_locations')->nullOnDelete();
        });
        Schema::table('demand_forecast_overrides', function (Blueprint $table): void {
            $table->dropUnique('demand_forecast_override_unique');
            $table->unique(['company_id', 'product_id', 'location_id', 'period_start', 'period_end'], 'demand_forecast_override_scope_unique');
            $table->index(['company_id', 'location_id', 'period_start', 'period_end'], 'demand_forecast_location_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('demand_forecast_overrides', function (Blueprint $table): void {
            $table->dropUnique('demand_forecast_override_scope_unique');
            $table->dropIndex('demand_forecast_location_period_index');
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
            $table->unique(['company_id', 'product_id', 'period_start', 'period_end'], 'demand_forecast_override_unique');
        });
    }
};
