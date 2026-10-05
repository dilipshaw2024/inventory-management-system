<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->foreignId('tax_registration_id')->nullable()->after('jurisdiction')->constrained('company_tax_registrations')->nullOnDelete();
            $table->index(['company_id', 'tax_registration_id'], 'tax_filings_company_registration_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->dropIndex('tax_filings_company_registration_idx');
            $table->dropForeign(['tax_registration_id']);
            $table->dropColumn('tax_registration_id');
        });
    }
};
