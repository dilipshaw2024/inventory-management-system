<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('supplier_id')->constrained('inventory_locations')->nullOnDelete();
            $table->index(['location_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropIndex(['location_id', 'status']);
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
