<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_line_id')->constrained('inventory_transfer_lines')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->timestamps();
            $table->index(['transfer_line_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_allocations');
    }
};
