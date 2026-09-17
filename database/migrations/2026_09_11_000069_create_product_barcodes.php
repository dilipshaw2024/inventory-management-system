<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code', 120)->unique();
            $table->enum('type', ['barcode', 'qrcode'])->default('barcode');
            $table->boolean('is_primary')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'is_primary']);
        });
    }
    public function down(): void { Schema::dropIfExists('product_barcodes'); }
};
