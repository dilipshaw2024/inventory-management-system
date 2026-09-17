<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_return_id')->nullable()->constrained('inventory_returns')->nullOnDelete();
            $table->string('claim_no', 80);
            $table->string('external_reference', 150)->nullable();
            $table->date('claim_date');
            $table->string('reason_code', 100);
            $table->text('description')->nullable();
            $table->decimal('claim_amount', 19, 6)->default(0);
            $table->string('status', 30)->default('open')->index();
            $table->text('resolution_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'claim_no'], 'supplier_claims_company_no_unique');
            $table->unique(['company_id', 'external_reference'], 'supplier_claims_company_external_unique');
            $table->index(['company_id', 'supplier_id', 'status', 'updated_at'], 'supplier_claims_sync_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_claims');
    }
};
