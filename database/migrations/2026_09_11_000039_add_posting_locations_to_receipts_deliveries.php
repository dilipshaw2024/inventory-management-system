<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('goods_receipts', function (Blueprint $table): void { $table->foreignId('location_id')->nullable()->after('purchase_order_id')->constrained('inventory_locations')->nullOnDelete(); });
        Schema::table('deliveries', function (Blueprint $table): void { $table->foreignId('location_id')->nullable()->after('sales_order_id')->constrained('inventory_locations')->nullOnDelete(); });
    }
    public function down(): void {
        Schema::table('deliveries', function (Blueprint $table): void { $table->dropForeign(['location_id']); $table->dropColumn('location_id'); });
        Schema::table('goods_receipts', function (Blueprint $table): void { $table->dropForeign(['location_id']); $table->dropColumn('location_id'); });
    }
};
