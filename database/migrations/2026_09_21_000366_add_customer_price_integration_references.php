<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_product_prices', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('id');
            $table->unique(['company_id', 'external_reference'], 'customer_prices_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_product_prices', function (Blueprint $table): void {
            $table->dropUnique('customer_prices_company_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
