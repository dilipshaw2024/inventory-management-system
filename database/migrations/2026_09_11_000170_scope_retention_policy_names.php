<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_retention_policies', function (Blueprint $table): void {
            $table->dropUnique(['name']);
            $table->unique(['company_id', 'name'], 'retention_policies_company_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('data_retention_policies', function (Blueprint $table): void {
            $table->dropUnique('retention_policies_company_name_unique');
            $table->unique('name');
        });
    }
};
