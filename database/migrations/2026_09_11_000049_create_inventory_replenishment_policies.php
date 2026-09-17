<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->decimal('reorder_point', 18, 6)->default(0);
            $table->decimal('safety_stock', 18, 6)->default(0);
            $table->decimal('min_stock', 18, 6)->default(0);
            $table->decimal('max_stock', 18, 6)->nullable();
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'location_id'], 'replenishment_product_location_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('inventory_replenishment_policies'); }
};
