<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('invoice_no');
            $table->unique(['company_id', 'external_reference'], 'purchase_invoices_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropUnique('purchase_invoices_company_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
