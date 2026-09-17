<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table): void {
                $table->decimal('payment_amount', 19, 6)->nullable()->after('amount');
                $table->decimal('exchange_rate', 24, 12)->nullable()->after('payment_amount');
                $table->index(['payment_id', 'voided_at']);
        });
        Schema::table('supplier_payment_allocations', function (Blueprint $table): void {
            $table->decimal('payment_amount', 19, 6)->nullable()->after('amount');
            $table->decimal('exchange_rate', 24, 12)->nullable()->after('payment_amount');
            $table->index(['supplier_payment_id', 'voided_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table): void {
            $table->dropIndex(['payment_id', 'voided_at']);
            $table->dropColumn(['payment_amount', 'exchange_rate']);
        });
        Schema::table('supplier_payment_allocations', function (Blueprint $table): void {
            $table->dropIndex(['supplier_payment_id', 'voided_at']);
            $table->dropColumn(['payment_amount', 'exchange_rate']);
        });
    }
};
