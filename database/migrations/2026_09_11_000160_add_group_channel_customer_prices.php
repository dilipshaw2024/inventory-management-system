<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new \RuntimeException('Migration 000160 currently requires the MySQL-compatible column alteration path.');
        }
        DB::statement('ALTER TABLE customer_product_prices MODIFY customer_id BIGINT UNSIGNED NULL');
        if (!Schema::hasColumn('customer_product_prices', 'customer_group')) {
            Schema::table('customer_product_prices', function (Blueprint $table): void {
                $table->string('customer_group', 100)->nullable();
            });
        }
        if (!Schema::hasColumn('customer_product_prices', 'sales_channel')) {
            Schema::table('customer_product_prices', function (Blueprint $table): void {
                $table->string('sales_channel', 50)->nullable();
            });
        }
        if (!DB::selectOne('SHOW INDEX FROM customer_product_prices WHERE Key_name = ?', ['customer_prices_scope_idx'])) {
            Schema::table('customer_product_prices', function (Blueprint $table): void {
                $table->index(['customer_group', 'sales_channel', 'product_id'], 'customer_prices_scope_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('customer_product_prices', function (Blueprint $table): void {
            $table->dropIndex(['customer_group', 'sales_channel', 'product_id']);
            $table->dropColumn(['customer_group', 'sales_channel']);
            $table->foreignId('customer_id')->nullable(false)->change();
        });
    }
};
