<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_location_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreignId('location_id')->constrained('inventory_locations')->cascadeOnDelete();
            $table->string('code', 120);
            $table->enum('type', ['barcode', 'qrcode'])->default('barcode');
            $table->boolean('is_primary')->default(false);
            $table->string('external_reference', 150)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'inventory_location_barcodes_company_code_unique');
            $table->unique(['location_id', 'external_reference'], 'inventory_location_barcodes_location_external_unique');
            $table->index(['location_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_location_barcodes');
    }
};
