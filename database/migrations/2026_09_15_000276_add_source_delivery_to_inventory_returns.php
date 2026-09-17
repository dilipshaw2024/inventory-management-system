<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->foreignId('source_delivery_id')->nullable()->after('source_goods_receipt_id')->constrained('deliveries')->nullOnDelete();
            $table->index(['company_id', 'source_delivery_id'], 'inventory_returns_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropIndex('inventory_returns_delivery_idx');
            $table->dropForeign(['source_delivery_id']);
            $table->dropColumn('source_delivery_id');
        });
    }
};
