<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('maintenance_parts', 'location_id')) {
            Schema::table('maintenance_parts', function (Blueprint $table): void {
                $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            });
        }
        if (!Schema::hasColumn('maintenance_parts', 'batch_id')) {
            Schema::table('maintenance_parts', function (Blueprint $table): void {
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            });
        }
        if (!Schema::hasColumn('maintenance_parts', 'serial_numbers')) {
            Schema::table('maintenance_parts', function (Blueprint $table): void {
                $table->text('serial_numbers')->nullable();
            });
        }
        if (!DB::selectOne('SHOW INDEX FROM maintenance_parts WHERE Key_name = ?', ['maintenance_part_inventory_idx'])) {
            Schema::table('maintenance_parts', function (Blueprint $table): void {
                $table->index(['maintenance_order_id', 'product_id', 'location_id'], 'maintenance_part_inventory_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('maintenance_parts', function (Blueprint $table): void {
            $table->dropIndex(['maintenance_order_id', 'product_id', 'location_id']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['batch_id']);
            $table->dropColumn(['location_id', 'batch_id', 'serial_numbers']);
        });
    }
};
