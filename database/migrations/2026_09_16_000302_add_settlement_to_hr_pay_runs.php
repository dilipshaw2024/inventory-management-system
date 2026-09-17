<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->foreignId('settlement_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->nullOnDelete();
            $table->date('paid_at')->nullable()->after('approved_at');
            $table->string('payment_reference', 150)->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('settlement_journal_entry_id');
            $table->dropColumn(['paid_at', 'payment_reference']);
        });
    }
};
