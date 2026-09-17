<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique(['entry_no']);
            $table->unique(['company_id', 'entry_no'], 'journal_entries_company_entry_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropUnique('journal_entries_company_entry_no_unique');
            $table->unique('entry_no');
        });
    }
};
