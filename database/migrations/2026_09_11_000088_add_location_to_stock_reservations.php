<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('product_id')->constrained('inventory_locations')->nullOnDelete();
            $table->index(['product_id', 'location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'location_id', 'status']);
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
