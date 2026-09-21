<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->string('reorder_point_method', 30)->default('fixed')->after('reorder_point');
            $table->unsignedSmallInteger('reorder_history_days')->default(90)->after('reorder_point_method');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->dropColumn(['reorder_point_method', 'reorder_history_days']);
        });
    }
};
