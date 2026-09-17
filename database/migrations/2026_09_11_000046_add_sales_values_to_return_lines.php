<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_return_lines', function (Blueprint $table): void {
            $table->decimal('unit_price', 19, 6)->nullable()->after('unit_cost');
            $table->decimal('tax_rate', 8, 4)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_return_lines', fn (Blueprint $table) => $table->dropColumn(['unit_price', 'tax_rate']));
    }
};
