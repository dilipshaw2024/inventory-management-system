<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('landed_costs', 'external_reference')) return;
        Schema::table('landed_costs', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('id');
            $table->unique(['company_id', 'external_reference'], 'landed_cost_company_external_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('landed_costs', 'external_reference')) return;
        Schema::table('landed_costs', function (Blueprint $table): void {
            $table->dropUnique('landed_cost_company_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
