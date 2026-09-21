<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('company_id');
            $table->unique(['company_id', 'external_reference'], 'sales_quotations_company_external_unique');
            $table->index(['company_id', 'updated_at'], 'sales_quotations_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->dropUnique('sales_quotations_company_external_unique');
            $table->dropIndex('sales_quotations_sync_idx');
            $table->dropColumn('external_reference');
        });
    }
};
