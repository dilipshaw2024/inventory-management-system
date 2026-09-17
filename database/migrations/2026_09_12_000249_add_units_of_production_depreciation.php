<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->decimal('depreciation_units_total', 19, 6)->nullable()->after('depreciation_method');
            $table->decimal('depreciation_units_used', 19, 6)->default(0)->after('depreciation_units_total');
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->dropColumn(['depreciation_units_total', 'depreciation_units_used']);
        });
    }
};
