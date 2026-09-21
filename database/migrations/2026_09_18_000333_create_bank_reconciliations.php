<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->date('statement_date');
            $table->decimal('opening_balance', 19, 6);
            $table->decimal('closing_balance', 19, 6);
            $table->decimal('book_balance', 19, 6)->default(0);
            $table->decimal('difference', 19, 6)->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->unsignedInteger('unmatched_count')->default(0);
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'bank_account_id', 'statement_date'], 'bank_reconciliation_company_account_date_unique');
            $table->index(['company_id', 'status', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliations');
    }
};
