<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_utilization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->date('as_of_date');
            $table->decimal('occupied_quantity', 20, 6)->default(0);
            $table->decimal('capacity', 20, 6)->nullable();
            $table->decimal('utilization_percent', 10, 4)->nullable();
            $table->decimal('occupied_weight_kg', 20, 6)->default(0);
            $table->decimal('capacity_weight_kg', 20, 6)->nullable();
            $table->decimal('weight_utilization_percent', 10, 4)->nullable();
            $table->decimal('occupied_volume_m3', 20, 6)->default(0);
            $table->decimal('capacity_volume_m3', 20, 6)->nullable();
            $table->decimal('volume_utilization_percent', 10, 4)->nullable();
            $table->unsignedInteger('descendant_count')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'location_id', 'as_of_date'], 'warehouse_util_snapshot_company_location_date');
            $table->index(['company_id', 'as_of_date']);
            $table->index(['company_id', 'warehouse_id', 'as_of_date'], 'warehouse_util_snapshots_company_warehouse_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_utilization_snapshots');
    }
};
