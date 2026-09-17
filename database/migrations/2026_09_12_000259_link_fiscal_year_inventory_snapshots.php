<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fiscal_years', 'inventory_snapshot_id')) {
            Schema::table('fiscal_years', function (Blueprint $table): void {
                $table->foreignId('inventory_snapshot_id')->nullable()->after('closed_by')->constrained('inventory_reconciliation_snapshots')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fiscal_years', 'inventory_snapshot_id')) {
            Schema::table('fiscal_years', function (Blueprint $table): void {
                $table->dropForeign(['inventory_snapshot_id']);
                $table->dropColumn('inventory_snapshot_id');
            });
        }
    }
};
