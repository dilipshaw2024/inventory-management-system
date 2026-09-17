<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique(['external_reference']);
            $table->unique(['company_id', 'external_reference'], 'journal_entries_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique('journal_entries_company_external_unique');
            $table->unique('external_reference');
        });
    }
};
