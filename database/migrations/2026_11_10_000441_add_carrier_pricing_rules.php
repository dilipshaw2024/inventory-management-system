<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_rate_cards', function (Blueprint $table): void {
            $table->decimal('minimum_charge', 19, 6)->default(0)->after('per_kg_amount');
            $table->decimal('fuel_surcharge_percent', 9, 6)->default(0)->after('minimum_charge');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_rate_cards', function (Blueprint $table): void {
            $table->dropColumn(['minimum_charge', 'fuel_surcharge_percent']);
        });
    }
};
