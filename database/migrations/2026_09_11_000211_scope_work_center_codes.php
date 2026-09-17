<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_centers', function (Blueprint $table): void {
            $table->dropUnique('work_centers_code_unique');
            $table->unique(['company_id', 'code'], 'work_centers_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('work_centers', function (Blueprint $table): void {
            $table->dropUnique('work_centers_company_code_unique');
            $table->unique('code', 'work_centers_code_unique');
        });
    }
};
