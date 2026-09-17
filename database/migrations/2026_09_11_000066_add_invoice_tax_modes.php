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
                $table->string('tax_mode', 12)->default('exclusive')->after('exchange_rate');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'purchase_invoices'] as $tableName) {
            if (!Schema::hasTable($tableName)) continue;
            Schema::table($tableName, function (Blueprint $table): void { $table->dropColumn('tax_mode'); });
        }
    }
};
