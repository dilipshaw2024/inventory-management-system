<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_status_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->enum('status', ['quarantine', 'damaged', 'scrap']);
            $table->decimal('quantity', 18, 6)->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'location_id', 'status'], 'status_balances_product_location_status_unique');
        });

        Schema::create('inventory_status_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no')->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->enum('from_status', ['available', 'quarantine', 'damaged']);
            $table->enum('to_status', ['quarantine', 'damaged', 'scrap']);
            $table->decimal('quantity', 18, 6);
            $table->text('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_status_transfers');
        Schema::dropIfExists('inventory_status_balances');
    }
};
