<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_filings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('filing_no', 80);
            $table->string('external_reference', 180);
            $table->string('return_type', 40)->default('indirect_tax');
            $table->date('period_from'); $table->date('period_to');
            $table->string('jurisdiction', 100)->nullable();
            $table->decimal('sales_tax', 18, 6)->default(0);
            $table->decimal('purchase_tax', 18, 6)->default(0);
            $table->decimal('net_tax', 18, 6)->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('filing_reference', 180)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'filing_no']);
            $table->unique(['company_id', 'external_reference']);
            $table->index(['company_id', 'period_from', 'period_to', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('tax_filings'); }
};
