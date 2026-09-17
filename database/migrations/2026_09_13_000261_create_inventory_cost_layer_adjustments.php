<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_cost_layer_adjustments')) return;
        Schema::create('inventory_cost_layer_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landed_cost_id')->constrained('landed_costs')->restrictOnDelete();
            $table->foreignId('cost_layer_id')->constrained('inventory_cost_layers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('old_unit_cost', 19, 6);
            $table->decimal('new_unit_cost', 19, 6);
            $table->decimal('adjustment_amount', 19, 6);
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['landed_cost_id', 'cost_layer_id'], 'landed_cost_layer_adjustment_unique');
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layer_adjustments');
    }
};
