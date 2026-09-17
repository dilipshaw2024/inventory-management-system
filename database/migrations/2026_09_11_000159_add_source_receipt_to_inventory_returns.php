<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->foreignId('source_goods_receipt_id')->nullable()->after('source_invoice_id')->constrained('goods_receipts')->nullOnDelete();
            $table->index(['source_goods_receipt_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropIndex(['source_goods_receipt_id', 'status']);
            $table->dropForeign(['source_goods_receipt_id']);
            $table->dropColumn('source_goods_receipt_id');
        });
    }
};
