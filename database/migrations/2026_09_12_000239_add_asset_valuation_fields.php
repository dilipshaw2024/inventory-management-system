<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->decimal('acquisition_cost', 19, 6)->default(0)->after('warranty_until');
            $table->decimal('salvage_value', 19, 6)->default(0)->after('acquisition_cost');
            $table->unsignedInteger('useful_life_months')->nullable()->after('salvage_value');
            $table->string('depreciation_method', 30)->default('straight_line')->after('useful_life_months');
            $table->date('in_service_date')->nullable()->after('depreciation_method');
            $table->decimal('accumulated_depreciation', 19, 6)->default(0)->after('in_service_date');
            $table->date('last_depreciated_on')->nullable()->after('accumulated_depreciation');
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->dropColumn([
                'acquisition_cost', 'salvage_value', 'useful_life_months',
                'depreciation_method', 'in_service_date', 'accumulated_depreciation',
                'last_depreciated_on',
            ]);
        });
    }
};
