<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_settlements', function (Blueprint $table): void {
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversal_journal_entry_id');
            $table->timestamp('reversed_at')->nullable()->after('reversal_reason');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tax_settlements', function (Blueprint $table): void {
            $table->dropForeign(['reversal_journal_entry_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversal_journal_entry_id', 'reversal_reason', 'reversed_at', 'reversed_by']);
        });
    }
};
