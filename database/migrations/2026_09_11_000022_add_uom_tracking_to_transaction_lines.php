<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_order_lines', 'goods_receipt_lines', 'sales_order_lines', 'delivery_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('uom_id')->nullable()->after('product_id')->constrained('units')->nullOnDelete();
                $table->decimal('uom_quantity', 18, 6)->nullable()->after('uom_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_order_lines', 'goods_receipt_lines', 'sales_order_lines', 'delivery_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['uom_id']);
                $table->dropColumn(['uom_id', 'uom_quantity']);
            });
        }
    }
};
