<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->text('unmatch_reason')->nullable()->after('matched_by');
            $table->timestamp('unmatched_at')->nullable()->after('unmatch_reason');
            $table->foreignId('unmatched_by')->nullable()->after('unmatched_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropForeign(['unmatched_by']);
            $table->dropColumn(['unmatch_reason', 'unmatched_at', 'unmatched_by']);
        });
    }
};
