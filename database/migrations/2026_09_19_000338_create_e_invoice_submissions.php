<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('provider', 80);
            $table->string('status', 30)->default('prepared');
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->json('provider_response')->nullable();
            $table->string('external_reference', 190)->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'invoice_id', 'provider'], 'einvoice_company_invoice_provider_unique');
            $table->index(['company_id', 'status']);
            $table->index(['provider', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_submissions');
    }
};
