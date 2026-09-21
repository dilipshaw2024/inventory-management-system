<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_cost_revaluation_runs MODIFY status ENUM('pending','approved','rejected','reversed') NOT NULL DEFAULT 'pending'");
        }
        Schema::table('inventory_cost_revaluation_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->after('journal_entry_id');
            $table->foreign('reversal_journal_entry_id', 'reval_runs_reversal_journal_fk')->references('id')->on('journal_entries')->nullOnDelete();
            $table->text('reversal_reason')->nullable()->after('reversal_journal_entry_id');
            $table->foreignId('reversed_by')->nullable()->after('reversal_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_cost_revaluation_runs', function (Blueprint $table): void {
            $table->dropForeign('reval_runs_reversal_journal_fk');
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversal_journal_entry_id', 'reversal_reason', 'reversed_by', 'reversed_at']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_cost_revaluation_runs MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }
    }
};
