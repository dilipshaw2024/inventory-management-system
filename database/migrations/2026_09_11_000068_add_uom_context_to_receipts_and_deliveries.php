<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['goods_receipt_lines', 'delivery_lines'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            // Migration 000022 already adds these columns to all transaction
            // lines. Keep this historical migration safe for clean installs
            // and upgrades where that earlier migration has run.
            if (Schema::hasColumn($tableName, 'uom_id') || Schema::hasColumn($tableName, 'uom_quantity')) continue;
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('uom_id')->nullable()->after('product_id')->constrained('units')->nullOnDelete();
                $table->decimal('uom_quantity', 18, 6)->nullable()->after('uom_id');
            });
        }
    }
    public function down(): void
    {
        // The columns are owned by migration 000022 when present. Do not
        // remove them here during rollback, or an older migration would be
        // made responsible for schema it did not create.
    }
};
