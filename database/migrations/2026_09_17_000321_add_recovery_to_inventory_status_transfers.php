<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inventory_status_transfers', 'recovery_product_id')) return;
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->foreignId('recovery_product_id')->nullable()->after('reason')->constrained('products')->nullOnDelete();
            $table->decimal('recovery_quantity', 18, 6)->nullable()->after('recovery_product_id');
            $table->decimal('recovery_unit_cost', 19, 6)->nullable()->after('recovery_quantity');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('inventory_status_transfers', 'recovery_product_id')) return;
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->dropForeign(['recovery_product_id']);
            $table->dropColumn(['recovery_product_id', 'recovery_quantity', 'recovery_unit_cost']);
        });
    }
};
