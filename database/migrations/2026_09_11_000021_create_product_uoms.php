<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_uoms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('conversion_to_stock', 18, 8)->default(1);
            $table->enum('usage', ['purchase', 'sales', 'both'])->default('both');
            $table->unsignedSmallInteger('decimal_places')->default(3);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_uoms');
    }
};
