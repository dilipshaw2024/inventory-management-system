<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('refund_no', 100);
            $table->foreignId('inventory_return_id')->constrained('inventory_returns')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 6);
            $table->string('currency_code', 3)->default('USD');
            $table->enum('method', ['cash', 'bank', 'card', 'transfer', 'other'])->default('bank');
            $table->string('reference')->nullable();
            $table->text('reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'refund_no'], 'customer_refunds_company_no_unique');
            $table->index(['company_id', 'customer_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('customer_refunds'); }
};
