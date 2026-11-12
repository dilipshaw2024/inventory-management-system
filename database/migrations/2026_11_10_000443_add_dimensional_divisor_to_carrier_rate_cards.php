<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_rate_cards', function (Blueprint $table): void {
            $table->decimal('dimensional_divisor', 18, 6)->default(0)->after('billable_weight_increment_kg');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_rate_cards', function (Blueprint $table): void {
            $table->dropColumn('dimensional_divisor');
        });
    }
};
