<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->foreignId('variance_journal_id')->nullable()->after('variance')->constrained('journal_entries')->nullOnDelete();
            $table->string('variance_accounting_status', 20)->default('pending')->after('variance_journal_id');
            $table->text('variance_accounting_message')->nullable()->after('variance_accounting_status');
            $table->index(['company_id', 'variance_accounting_status'], 'pos_sessions_company_variance_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->dropForeign(['variance_journal_id']);
            $table->dropIndex('pos_sessions_company_variance_status_idx');
            $table->dropColumn(['variance_journal_id', 'variance_accounting_status', 'variance_accounting_message']);
        });
    }
};
