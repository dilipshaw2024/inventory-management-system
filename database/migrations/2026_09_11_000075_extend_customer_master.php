<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('tax_number', 100)->nullable()->after('address');
            $table->string('customer_group', 100)->nullable()->after('tax_number');
            $table->string('sales_channel', 50)->nullable()->after('customer_group');
            $table->string('currency_code', 3)->nullable()->after('sales_channel');
            $table->boolean('is_active')->default(true)->after('currency_code');
            $table->index(['customer_group', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['customer_group', 'is_active']);
            $table->dropColumn(['tax_number', 'customer_group', 'sales_channel', 'currency_code', 'is_active']);
        });
    }
};
