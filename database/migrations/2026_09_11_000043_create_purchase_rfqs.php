<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->string('rfq_no')->unique();
            $table->date('issue_date');
            $table->date('response_due')->nullable();
            $table->enum('status', ['draft', 'submitted', 'closed', 'cancelled'])->default('draft');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('purchase_rfq_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_qty', 18, 6);
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['purchase_rfq_id', 'product_id']);
        });
        Schema::create('purchase_rfq_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_rfq_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['invited', 'quoted', 'declined'])->default('invited');
            $table->timestamp('quoted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_rfq_id', 'supplier_id']);
        });
        Schema::create('purchase_supplier_quotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_rfq_supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_rfq_line_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_price', 19, 6);
            $table->unsignedInteger('lead_days')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_rfq_supplier_id', 'purchase_rfq_line_id'], 'rfq_supplier_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_supplier_quotations');
        Schema::dropIfExists('purchase_rfq_suppliers');
        Schema::dropIfExists('purchase_rfq_lines');
        Schema::dropIfExists('purchase_rfqs');
    }
};
