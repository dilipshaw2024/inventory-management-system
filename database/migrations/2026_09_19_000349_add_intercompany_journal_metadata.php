<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('intercompany_reference', 150)->nullable()->after('consolidation_reference');
            $table->foreignId('counterparty_company_id')->nullable()->after('intercompany_reference')->constrained('companies')->nullOnDelete();
            $table->index(['intercompany_reference', 'counterparty_company_id'], 'journal_entries_intercompany_index');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropIndex('journal_entries_intercompany_index');
            $table->dropForeign(['counterparty_company_id']);
            $table->dropColumn(['intercompany_reference', 'counterparty_company_id']);
        });
    }
};
