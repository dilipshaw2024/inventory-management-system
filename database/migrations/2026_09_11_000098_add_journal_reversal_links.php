<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreignId('reversal_of_id')->nullable()->after('status')->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('posted_at');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'reversal_of_id']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['reversal_of_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropIndex(['company_id', 'reversal_of_id']);
            $table->dropColumn(['reversal_of_id', 'reversed_at', 'reversed_by']);
        });
    }
};
