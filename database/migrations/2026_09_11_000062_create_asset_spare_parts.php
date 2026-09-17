<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_spare_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('service_assets')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_per_service', 18, 6)->default(1);
            $table->decimal('minimum_stock', 18, 6)->default(0);
            $table->decimal('maximum_stock', 18, 6)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['asset_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_spare_parts');
    }
};
