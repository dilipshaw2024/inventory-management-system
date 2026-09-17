<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_attributes', function (Blueprint $table): void {
            $table->id(); $table->string('name')->unique(); $table->boolean('is_active')->default(true); $table->timestamps();
        });
        Schema::create('product_attribute_values', function (Blueprint $table): void {
            $table->id(); $table->foreignId('attribute_id')->constrained('product_attributes')->cascadeOnDelete(); $table->string('value'); $table->timestamps(); $table->unique(['attribute_id', 'value']);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('parent_product_id')->nullable()->after('id')->constrained('products')->nullOnDelete();
            $table->boolean('is_variant')->default(false)->after('parent_product_id');
        });
        Schema::create('product_attribute_assignments', function (Blueprint $table): void {
            $table->id(); $table->foreignId('product_id')->constrained()->cascadeOnDelete(); $table->foreignId('attribute_id')->constrained('product_attributes')->cascadeOnDelete(); $table->foreignId('attribute_value_id')->constrained('product_attribute_values')->cascadeOnDelete(); $table->timestamps(); $table->unique(['product_id', 'attribute_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('product_attribute_assignments');
        Schema::table('products', function (Blueprint $table): void { $table->dropForeign(['parent_product_id']); $table->dropColumn(['parent_product_id', 'is_variant']); });
        Schema::dropIfExists('product_attribute_values'); Schema::dropIfExists('product_attributes');
    }
};
