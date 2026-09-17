<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_parts', function (Blueprint $table): void {
            $table->decimal('returned_quantity', 18, 6)->default(0)->after('quantity');
        });

        Schema::create('maintenance_part_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('maintenance_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('maintenance_part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->text('serial_numbers')->nullable();
            $table->string('reason', 2000);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['maintenance_order_id', 'maintenance_part_id'], 'maintenance_part_return_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_part_returns');
        Schema::table('maintenance_parts', function (Blueprint $table): void {
            $table->dropColumn('returned_quantity');
        });
    }
};
