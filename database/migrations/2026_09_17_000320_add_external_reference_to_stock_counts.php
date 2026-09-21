<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stock_counts', 'external_reference')) return;
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('count_no');
            $table->unique(['company_id', 'external_reference'], 'stock_counts_company_external_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('stock_counts', 'external_reference')) return;
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->dropUnique('stock_counts_company_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
