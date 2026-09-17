<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('minimum_quantity', 18, 6)->default(1);
            $table->decimal('unit_price', 19, 6);
            $table->string('currency_code', 3)->default('USD');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('supplier_sku')->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['supplier_id', 'product_id', 'minimum_quantity'], 'supplier_product_qty_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_prices');
    }
};
