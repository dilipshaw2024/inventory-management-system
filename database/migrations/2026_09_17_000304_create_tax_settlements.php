<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('settlement_no', 80);
            $table->string('external_reference', 180);
            $table->date('period_from'); $table->date('period_to');
            $table->string('jurisdiction', 100)->nullable();
            $table->decimal('net_tax', 18, 6); $table->date('paid_at');
            $table->string('payment_reference', 150)->nullable();
            $table->string('status', 20)->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'settlement_no']);
            $table->unique(['company_id', 'external_reference']);
            $table->index(['company_id', 'period_from', 'period_to']);
        });
    }

    public function down(): void { Schema::dropIfExists('tax_settlements'); }
};
