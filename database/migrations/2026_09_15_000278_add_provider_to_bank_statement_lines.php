<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->string('provider', 50)->default('generic')->after('bank_account_id');
            $table->json('raw_payload')->nullable()->after('description');
        });
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropUnique('bank_lines_company_external_unique');
            $table->unique(['company_id', 'provider', 'external_reference'], 'bank_lines_company_provider_external_unique');
            $table->index(['company_id', 'provider', 'updated_at'], 'bank_lines_provider_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropUnique('bank_lines_company_provider_external_unique');
            $table->dropIndex('bank_lines_provider_sync_idx');
            $table->unique(['company_id', 'external_reference'], 'bank_lines_company_external_unique');
            $table->dropColumn(['provider', 'raw_payload']);
        });
    }
};
