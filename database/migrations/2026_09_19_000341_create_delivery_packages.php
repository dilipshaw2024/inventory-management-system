<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_packages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('package_no', 100);
            $table->string('external_reference', 150)->nullable();
            $table->enum('status', ['packed', 'dispatched', 'delivered', 'cancelled'])->default('packed');
            $table->string('carrier', 255)->nullable();
            $table->string('tracking_no', 255)->nullable();
            $table->decimal('weight', 18, 6)->nullable();
            $table->string('weight_unit', 12)->nullable();
            $table->decimal('length', 18, 6)->nullable();
            $table->decimal('width', 18, 6)->nullable();
            $table->decimal('height', 18, 6)->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'package_no'], 'delivery_packages_company_no_unique');
            $table->unique(['company_id', 'external_reference'], 'delivery_packages_company_external_unique');
            $table->index(['company_id', 'delivery_id', 'updated_at'], 'delivery_packages_sync_idx');
        });

        Schema::create('delivery_package_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('delivery_packages')->cascadeOnDelete();
            $table->foreignId('delivery_line_id')->constrained('delivery_lines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->text('serial_numbers')->nullable();
            $table->timestamps();
            $table->unique(['package_id', 'delivery_line_id'], 'delivery_package_lines_package_delivery_unique');
            $table->index(['delivery_line_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_package_lines');
        Schema::dropIfExists('delivery_packages');
    }
};
