<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->foreignId('inventory_serial_id')->nullable()->after('product_id')->unique()->constrained('inventory_serials')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->dropForeign(['inventory_serial_id']);
            $table->dropUnique(['inventory_serial_id']);
            $table->dropColumn('inventory_serial_id');
        });
    }
};
