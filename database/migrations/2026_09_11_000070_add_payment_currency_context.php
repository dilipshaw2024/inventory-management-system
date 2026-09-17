<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'supplier_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('currency_code', 3)->nullable()->after('id');
                $table->decimal('exchange_rate', 24, 12)->default(1)->after('currency_code');
                $table->decimal('base_amount', 19, 6)->nullable()->after('exchange_rate');
                $table->index(['currency_code', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'supplier_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex([$tableName.'_currency_code_created_at_index']);
                $table->dropColumn(['currency_code', 'exchange_rate', 'base_amount']);
            });
        }
    }
};
