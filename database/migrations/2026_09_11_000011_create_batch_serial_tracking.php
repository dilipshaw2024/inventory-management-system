<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('batch_no');
            $table->string('lot_no')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('best_before_date')->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'batch_no']);
            $table->index(['product_id', 'expiry_date']);
        });

        Schema::create('inventory_serials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('serial_no');
            $table->enum('status', ['available', 'reserved', 'issued', 'returned', 'scrapped', 'quarantine'])->default('available');
            $table->date('warranty_until')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'serial_no']);
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->index(['product_id', 'batch_id', 'posted_at']);
        });

        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('batch_no')->nullable();
            $table->text('serial_numbers')->nullable();
            $table->date('manufacturing_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->date('best_before_date')->nullable();
            $table->date('warranty_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_lines', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropColumn(['batch_id', 'batch_no', 'serial_numbers', 'manufacturing_date', 'expiry_date', 'best_before_date', 'warranty_until']);
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropForeign(['serial_id']);
            $table->dropColumn(['batch_id', 'serial_id']);
        });
        Schema::dropIfExists('inventory_serials');
        Schema::dropIfExists('inventory_batches');
    }
};
