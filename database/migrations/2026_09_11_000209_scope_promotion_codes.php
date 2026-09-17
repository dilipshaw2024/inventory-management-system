<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropUnique('promotions_code_unique');
            $table->unique(['company_id', 'code'], 'promotions_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->dropUnique('promotions_company_code_unique');
            $table->unique('code', 'promotions_code_unique');
        });
    }
};
