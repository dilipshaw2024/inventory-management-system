<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_details', function (Blueprint $table): void {
            $table->json('tax_components')->nullable()->after('tax_amount');
        });

        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->json('tax_components')->nullable()->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_lines', function (Blueprint $table): void {
            $table->dropColumn('tax_components');
        });

        Schema::table('invoice_details', function (Blueprint $table): void {
            $table->dropColumn('tax_components');
        });
    }
};
