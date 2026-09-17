<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table): void {
            $table->date('effective_from')->nullable()->after('jurisdiction');
            $table->date('effective_until')->nullable()->after('effective_from');
            $table->dropUnique('tax_rates_company_code_unique');
            $table->unique(['company_id', 'code', 'effective_from'], 'tax_rates_company_code_from_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table): void {
            $table->dropUnique('tax_rates_company_code_from_unique');
            $table->unique(['company_id', 'code'], 'tax_rates_company_code_unique');
            $table->dropColumn(['effective_from', 'effective_until']);
        });
    }
};
