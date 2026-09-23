<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->string('settlement_status', 20)->default('not_required')->after('status');
            $table->foreignId('settlement_journal_id')->nullable()->after('settlement_status')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('settlement_reversal_journal_id')->nullable()->after('settlement_journal_id')->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('settled_by')->nullable()->after('settlement_reversal_journal_id')->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settled_by');
            $table->text('settlement_reversal_reason')->nullable()->after('settled_at');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table): void {
            $table->dropForeign(['settlement_journal_id']);
            $table->dropForeign(['settlement_reversal_journal_id']);
            $table->dropForeign(['settled_by']);
            $table->dropColumn(['settlement_status', 'settlement_journal_id', 'settlement_reversal_journal_id', 'settled_by', 'settled_at', 'settlement_reversal_reason']);
        });
    }
};
