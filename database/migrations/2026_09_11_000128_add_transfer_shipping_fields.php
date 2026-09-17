<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->string('carrier_name', 150)->nullable()->after('description');
            $table->string('tracking_number', 150)->nullable()->after('carrier_name');
            $table->date('expected_arrival')->nullable()->after('tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->dropColumn(['carrier_name', 'tracking_number', 'expected_arrival']);
        });
    }
};
