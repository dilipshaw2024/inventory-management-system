<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->foreignId('settlement_reversal_journal_id')->nullable()->after('settled_by')->constrained('journal_entries')->nullOnDelete();
            $table->text('settlement_reversal_reason')->nullable()->after('settlement_reversal_journal_id');
            $table->timestamp('settlement_reversed_at')->nullable()->after('settlement_reversal_reason');
            $table->foreignId('settlement_reversed_by')->nullable()->after('settlement_reversed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropForeign(['settlement_reversal_journal_id']);
            $table->dropForeign(['settlement_reversed_by']);
            $table->dropColumn(['settlement_reversal_journal_id', 'settlement_reversal_reason', 'settlement_reversed_at', 'settlement_reversed_by']);
        });
    }
};
