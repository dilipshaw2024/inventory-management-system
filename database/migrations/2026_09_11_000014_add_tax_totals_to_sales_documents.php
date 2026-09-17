<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('subtotal_amount', 19, 6)->default(0);
            $table->decimal('tax_amount', 19, 6)->default(0);
            $table->decimal('total_amount', 19, 6)->default(0);
        });

        Schema::table('invoice_details', function (Blueprint $table): void {
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_amount', 19, 6)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_details', function (Blueprint $table): void {
            $table->dropColumn(['tax_rate', 'tax_amount']);
        });
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['subtotal_amount', 'tax_amount', 'total_amount']);
        });
    }
};
