<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movement_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 19, 6)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'batch_id']);
            $table->index(['movement_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_allocations');
    }
};
