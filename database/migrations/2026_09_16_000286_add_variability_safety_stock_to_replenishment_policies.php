<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->string('safety_stock_method', 30)->default('fixed')->after('safety_stock');
            $table->decimal('service_level_z', 8, 4)->default(1.6500)->after('safety_stock_method');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void { $table->dropColumn(['safety_stock_method', 'service_level_z']); });
    }
};
