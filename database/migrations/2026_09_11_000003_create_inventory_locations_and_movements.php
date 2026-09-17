<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('name');
            $table->string('code');
            $table->enum('type', ['warehouse', 'zone', 'rack', 'shelf', 'bin'])->default('bin');
            $table->decimal('capacity', 18, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['warehouse_id', 'code']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->enum('movement_type', [
                'opening', 'receipt', 'issue', 'transfer_in', 'transfer_out',
                'adjustment_in', 'adjustment_out', 'return_in', 'return_out',
                'scrap', 'quarantine_in', 'quarantine_out', 'reservation', 'release',
            ]);
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 19, 6)->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_no')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();
            $table->index(['reference_type', 'reference_id']);
            $table->index(['product_id', 'location_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_locations');
    }
};
