<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->unique(['company_id', 'code'], 'tax_rates_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table): void {
            $table->dropUnique('tax_rates_company_code_unique');
            $table->unique('code');
        });
    }
};
