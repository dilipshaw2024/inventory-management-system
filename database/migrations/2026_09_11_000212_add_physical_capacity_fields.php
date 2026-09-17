<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('weight_kg', 18, 6)->nullable()->after('quantity');
            $table->decimal('length_m', 18, 6)->nullable()->after('weight_kg');
            $table->decimal('width_m', 18, 6)->nullable()->after('length_m');
            $table->decimal('height_m', 18, 6)->nullable()->after('width_m');
        });
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->decimal('capacity_weight_kg', 18, 6)->nullable()->after('capacity');
            $table->decimal('capacity_volume_m3', 18, 6)->nullable()->after('capacity_weight_kg');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table): void { $table->dropColumn(['capacity_weight_kg', 'capacity_volume_m3']); });
        Schema::table('products', function (Blueprint $table): void { $table->dropColumn(['weight_kg', 'length_m', 'width_m', 'height_m']); });
    }
};
