<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->boolean('consolidation_elimination')->default(false)->after('status');
            $table->string('consolidation_reference', 150)->nullable()->after('consolidation_elimination');
            $table->index(['company_id', 'consolidation_elimination', 'date'], 'journal_entries_consolidation_index');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex('journal_entries_consolidation_index');
            $table->dropColumn(['consolidation_elimination', 'consolidation_reference']);
        });
    }
};
