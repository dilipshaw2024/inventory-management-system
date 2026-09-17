<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropUnique(['claim_no']);
            $table->string('external_reference', 150)->nullable()->after('id');
            $table->unique(['company_id', 'claim_no'], 'warranty_claims_company_no_unique');
            $table->unique(['company_id', 'external_reference'], 'warranty_claims_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropUnique('warranty_claims_company_no_unique');
            $table->dropUnique('warranty_claims_company_external_unique');
            $table->dropColumn('external_reference');
            $table->unique('claim_no');
        });
    }
};
