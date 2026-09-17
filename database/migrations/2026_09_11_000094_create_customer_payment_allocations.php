<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::create('customer_payment_allocations', function (Blueprint $table): void { $table->id(); $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete(); $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete(); $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete(); $table->decimal('amount', 19, 6); $table->timestamp('allocated_at'); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps(); $table->unique(['payment_id', 'invoice_id']); $table->index(['company_id', 'invoice_id']); }); } public function down(): void { Schema::dropIfExists('customer_payment_allocations'); } };
