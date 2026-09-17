<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('sku')->nullable()->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('hsn_sac_code')->nullable();
            $table->decimal('purchase_price', 19, 6)->nullable();
            $table->decimal('sales_price', 19, 6)->nullable();
            $table->decimal('min_stock', 18, 6)->default(0);
            $table->decimal('max_stock', 18, 6)->nullable();
            $table->decimal('reorder_level', 18, 6)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->string('tracking_type')->default('none');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['brand_id']);
            $table->dropColumn(['brand_id', 'sku', 'barcode', 'hsn_sac_code', 'purchase_price', 'sales_price', 'min_stock', 'max_stock', 'reorder_level', 'tax_rate', 'tracking_type']);
        });
        Schema::dropIfExists('brands');
    }
};
