<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_type', 30)->default('stock')->after('tracking_type');
            $table->string('lifecycle_status', 30)->default('active')->after('product_type');
            $table->boolean('can_purchase')->default(true)->after('lifecycle_status');
            $table->boolean('can_sell')->default(true)->after('can_purchase');
            $table->boolean('is_stock_item')->default(true)->after('can_sell');
            $table->index(['company_id', 'lifecycle_status']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['products_company_id_lifecycle_status_index']);
            $table->dropColumn(['product_type', 'lifecycle_status', 'can_purchase', 'can_sell', 'is_stock_item']);
        });
    }
};
