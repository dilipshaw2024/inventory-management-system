<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_costs', function (Blueprint $table): void {
            $table->id();
            $table->string('cost_no')->unique();
            $table->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $table->string('cost_type')->default('freight');
            $table->decimal('amount', 19, 6);
            $table->enum('allocation_method', ['by_value', 'by_quantity'])->default('by_value');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('landed_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 19, 6);
            $table->decimal('per_unit_amount', 19, 6);
            $table->timestamps();
            $table->unique(['landed_cost_id', 'goods_receipt_line_id'], 'landed_cost_receipt_line_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('landed_cost_allocations'); Schema::dropIfExists('landed_costs'); }
};
