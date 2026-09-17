<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_bundle_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bundle_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['bundle_product_id', 'component_product_id'], 'bundle_component_unique');
            $table->index(['company_id', 'bundle_product_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('product_bundle_components'); }
};
