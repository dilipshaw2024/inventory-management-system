<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->boolean('stackable')->default(false)->after('is_active');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->json('promotion_ids')->nullable()->after('promotion_id');
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->json('promotion_ids')->nullable()->after('promotion_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropColumn('promotion_ids'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('promotion_ids'));
        Schema::table('promotions', fn (Blueprint $table) => $table->dropColumn('stackable'));
    }
};
