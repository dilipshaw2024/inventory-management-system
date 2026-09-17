<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('tax_exempt')->default(false)->after('tax_number');
                $table->string('tax_exemption_number')->nullable()->after('tax_exempt');
            });
        }
        foreach (['invoices', 'purchase_invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->boolean('tax_exempt')->default(false)->after('tax_mode');
                $table->string('tax_exemption_number')->nullable()->after('tax_exempt');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'purchase_invoices', 'customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['tax_exempt', 'tax_exemption_number']);
            });
        }
    }
};
