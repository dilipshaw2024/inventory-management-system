<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_refunds', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('refund_no');
            $table->unique(['company_id', 'external_reference'], 'customer_refunds_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_refunds', function (Blueprint $table): void {
            $table->dropUnique('customer_refunds_company_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
