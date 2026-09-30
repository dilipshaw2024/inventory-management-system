<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('external_reference', 150)->nullable();
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 19, 6)->default(0);
            $table->decimal('material_cost', 19, 6)->default(0);
            $table->decimal('operation_cost', 19, 6)->default(0);
            $table->decimal('byproduct_cost', 19, 6)->default(0);
            $table->decimal('net_cost', 19, 6)->default(0);
            $table->json('serial_numbers')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('best_before_date')->nullable();
            $table->date('warranty_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'production_receipts_company_external_unique');
            $table->index(['company_id', 'production_order_id', 'created_at'], 'production_receipts_order_created_idx');
            $table->index(['company_id', 'product_id', 'batch_id'], 'production_receipts_product_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_receipts');
    }
};
