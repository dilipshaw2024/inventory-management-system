<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->unique(['company_id', 'code'], 'brands_company_code_unique');
        });
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique(['code']);
            $table->unique(['company_id', 'code'], 'categories_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropUnique('categories_company_code_unique');
            $table->unique('code');
        });
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique('brands_company_code_unique');
            $table->unique('code');
        });
    }
};
