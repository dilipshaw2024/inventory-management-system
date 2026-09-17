<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('tax_jurisdiction', 100)->nullable()->after('tax_number');
            $table->index(['company_id', 'tax_jurisdiction'], 'customers_company_tax_jurisdiction_index');
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('tax_jurisdiction', 100)->nullable()->after('tax_number');
            $table->index(['company_id', 'tax_jurisdiction'], 'suppliers_company_tax_jurisdiction_index');
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('tax_jurisdiction', 100)->nullable()->after('tax_exemption_number');
        });
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->string('tax_jurisdiction', 100)->nullable()->after('tax_exemption_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn('tax_jurisdiction'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('tax_jurisdiction'));
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex('suppliers_company_tax_jurisdiction_index');
            $table->dropColumn('tax_jurisdiction');
        });
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('customers_company_tax_jurisdiction_index');
            $table->dropColumn('tax_jurisdiction');
        });
    }
};
