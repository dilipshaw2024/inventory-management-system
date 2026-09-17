<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transfer_serials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_line_id')->constrained('inventory_transfer_lines')->cascadeOnDelete();
            $table->foreignId('serial_id')->constrained('inventory_serials')->restrictOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->unique(['transfer_line_id', 'serial_id']);
            $table->index(['serial_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_serials');
    }
};
