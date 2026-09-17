<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->foreignId('warehouse_id')->nullable()->after('branch_id')->constrained('warehouses')->nullOnDelete();
            $table->string('currency_code', 3)->nullable()->after('is_active');
            $table->enum('default_tax_mode', ['exclusive', 'inclusive'])->nullable()->after('currency_code');
            $table->boolean('allow_negative_stock')->default(false)->after('default_tax_mode');
            $table->json('pos_settings')->nullable()->after('allow_negative_stock');
            $table->index(['branch_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex(['branch_id', 'warehouse_id']);
            $table->dropColumn(['warehouse_id', 'currency_code', 'default_tax_mode', 'allow_negative_stock', 'pos_settings']);
        });
    }
};
