<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->foreignId('settlement_reversal_journal_entry_id')->nullable()->after('settlement_journal_entry_id')->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('settlement_reversed_at')->nullable()->after('payment_reference');
            $table->foreignId('settlement_reversed_by')->nullable()->after('settlement_reversed_at')->constrained('users')->nullOnDelete();
            $table->text('settlement_reversal_reason')->nullable()->after('settlement_reversed_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('settlement_reversal_journal_entry_id');
            $table->dropConstrainedForeignId('settlement_reversed_by');
            $table->dropColumn(['settlement_reversed_at', 'settlement_reversal_reason']);
        });
    }
};
