<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('external_reference', 150)->nullable()->after('id');
                $table->unique(['company_id', 'external_reference'], $table->getTable().'_company_external_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropUnique($tableName.'_company_external_unique');
                $table->dropColumn('external_reference');
            });
        }
    }
};
