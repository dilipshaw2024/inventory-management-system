<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('company_id');
            $table->unique(['company_id', 'external_reference'], 'chart_accounts_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->dropUnique('chart_accounts_company_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
