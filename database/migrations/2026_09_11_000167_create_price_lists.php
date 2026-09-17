<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('list_type', ['sales', 'purchase']);
            $table->string('currency_code', 3);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'list_type', 'is_active']);
        });

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_list_id')->constrained('price_lists')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('minimum_quantity', 19, 6)->default(1);
            $table->decimal('unit_price', 19, 6);
            $table->decimal('discount_percent', 8, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['price_list_id', 'product_id', 'minimum_quantity'], 'price_list_product_qty_unique');
            $table->index(['company_id', 'product_id', 'is_active']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('sales_price_list_id')->nullable()->after('currency_code')->constrained('price_lists')->nullOnDelete();
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignId('purchase_price_list_id')->nullable()->after('is_active')->constrained('price_lists')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void { $table->dropForeign(['purchase_price_list_id']); $table->dropColumn('purchase_price_list_id'); });
        Schema::table('customers', function (Blueprint $table): void { $table->dropForeign(['sales_price_list_id']); $table->dropColumn('sales_price_list_id'); });
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
    }
};
