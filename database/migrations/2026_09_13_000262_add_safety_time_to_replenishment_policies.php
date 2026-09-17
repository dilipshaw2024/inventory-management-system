<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->unsignedInteger('safety_time_days')->default(0)->after('lead_time_days');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->dropColumn('safety_time_days');
        });
    }
};
