<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->decimal('original_quantity', 18, 6);
            $table->decimal('remaining_quantity', 18, 6);
            $table->decimal('unit_cost', 19, 6);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
            $table->index(['product_id', 'location_id', 'remaining_quantity', 'received_at'], 'cost_layers_product_location_remaining_received_idx');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inventory_cost_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cost_layer_id')->constrained('inventory_cost_layers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 19, 6);
            $table->decimal('total_cost', 19, 6);
            $table->string('costing_method')->default('fifo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_consumptions');
        Schema::dropIfExists('inventory_cost_layers');
    }
};
