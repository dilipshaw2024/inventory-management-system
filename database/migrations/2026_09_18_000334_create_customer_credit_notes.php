<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('credit_no', 80);
            $table->string('external_reference', 150)->nullable();
            $table->date('credit_date');
            $table->decimal('subtotal_amount', 19, 6)->default(0);
            $table->decimal('tax_amount', 19, 6)->default(0);
            $table->decimal('total_amount', 19, 6)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'credit_no'], 'customer_credit_notes_company_no_unique');
            $table->unique(['company_id', 'external_reference'], 'customer_credit_notes_company_external_unique');
            $table->index(['company_id', 'customer_id', 'status', 'updated_at'], 'customer_credit_notes_sync_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_notes');
    }
};
