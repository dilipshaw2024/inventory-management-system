<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_refunds', function (Blueprint $table): void {
            $table->decimal('exchange_rate', 20, 12)->default(1)->after('currency_code');
            $table->decimal('base_amount', 18, 6)->default(0)->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('customer_refunds', function (Blueprint $table): void {
            $table->dropColumn(['exchange_rate', 'base_amount']);
        });
    }
};
