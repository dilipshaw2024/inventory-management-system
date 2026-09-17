<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'purchase_invoices'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('currency_code', 3)->nullable()->after('id');
                $table->decimal('exchange_rate', 24, 12)->default(1)->after('currency_code');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'purchase_invoices'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['currency_code', 'exchange_rate']);
            });
        }
    }
};
