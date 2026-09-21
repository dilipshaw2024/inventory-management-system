<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('inventory_status_transfers', 'external_reference')) return;
        $duplicates = DB::table('inventory_status_transfers')
            ->select('company_id', 'external_reference')
            ->whereNotNull('external_reference')
            ->groupBy('company_id', 'external_reference')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($duplicates) throw new \RuntimeException('Cannot enforce unique status-transfer external references while duplicate legacy references exist. Resolve the duplicates and rerun the migration.');
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'external_reference']);
            $table->unique(['company_id', 'external_reference'], 'status_transfers_company_external_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('inventory_status_transfers', 'external_reference')) return;
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->dropUnique('status_transfers_company_external_unique');
            $table->index(['company_id', 'external_reference']);
        });
    }
};
