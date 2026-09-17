<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_lines', function (Blueprint $table): void {
            $table->text('issued_serial_numbers')->nullable()->after('serial_numbers');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_lines', function (Blueprint $table): void { $table->dropColumn('issued_serial_numbers'); });
    }
};
