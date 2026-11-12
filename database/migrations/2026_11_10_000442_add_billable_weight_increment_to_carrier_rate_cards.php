<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_rate_cards', function (Blueprint $table): void {
            $table->decimal('billable_weight_increment_kg', 18, 6)->default(0)->after('fuel_surcharge_percent');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_rate_cards', function (Blueprint $table): void {
            $table->dropColumn('billable_weight_increment_kg');
        });
    }
};
