<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->foreignId('settlement_journal_id')->nullable()->after('matched_id')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('settlement_account_id')->nullable()->after('settlement_journal_id')->constrained('chart_of_accounts')->nullOnDelete();
            $table->text('settlement_reason')->nullable()->after('settlement_account_id');
            $table->timestamp('settled_at')->nullable()->after('settlement_reason');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropForeign(['settlement_journal_id']);
            $table->dropForeign(['settlement_account_id']);
            $table->dropForeign(['settled_by']);
            $table->dropColumn(['settlement_journal_id', 'settlement_account_id', 'settlement_reason', 'settled_at', 'settled_by']);
        });
    }
};
