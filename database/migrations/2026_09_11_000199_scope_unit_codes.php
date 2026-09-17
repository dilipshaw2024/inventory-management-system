<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->unique(['company_id', 'code'], 'units_company_code_unique');
            // Add the replacement first: the original index also supports
            // the company_id foreign key and cannot be dropped temporarily.
            $table->dropIndex(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->dropUnique('units_company_code_unique');
            $table->index(['company_id', 'code']);
        });
    }
};
