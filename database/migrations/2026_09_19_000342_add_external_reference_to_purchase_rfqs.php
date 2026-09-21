<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_rfqs', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('company_id');
            $table->unique(['company_id', 'external_reference'], 'purchase_rfqs_company_external_unique');
            $table->index(['company_id', 'updated_at'], 'purchase_rfqs_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_rfqs', function (Blueprint $table): void {
            $table->dropUnique('purchase_rfqs_company_external_unique');
            $table->dropIndex('purchase_rfqs_sync_idx');
            $table->dropColumn('external_reference');
        });
    }
};
