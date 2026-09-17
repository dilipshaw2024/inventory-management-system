<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_return_lines', function (Blueprint $table): void { $table->text('serial_numbers')->nullable()->after('unit_cost'); });
    }

    public function down(): void
    {
        Schema::table('inventory_return_lines', function (Blueprint $table): void { $table->dropColumn('serial_numbers'); });
    }
};
