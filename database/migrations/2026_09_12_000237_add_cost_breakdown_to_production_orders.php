<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->decimal('material_cost', 19, 6)->default(0)->after('completed_quantity');
            $table->decimal('operation_cost', 19, 6)->default(0)->after('material_cost');
            $table->decimal('byproduct_cost', 19, 6)->default(0)->after('operation_cost');
            $table->decimal('production_cost', 19, 6)->default(0)->after('byproduct_cost');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropColumn(['material_cost', 'operation_cost', 'byproduct_cost', 'production_cost']);
        });
    }
};
