<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_cost_revaluation_runs', function (Blueprint $table): void {
            $table->foreignId('journal_entry_id')->nullable()->after('total_variance')->constrained('journal_entries')->nullOnDelete();
            $table->string('accounting_status', 30)->default('not_posted')->after('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_cost_revaluation_runs', function (Blueprint $table): void {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn(['journal_entry_id', 'accounting_status']);
        });
    }
};
