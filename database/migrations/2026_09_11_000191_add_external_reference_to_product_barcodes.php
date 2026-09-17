<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('code');
            $table->unique(['product_id', 'external_reference'], 'product_barcode_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_barcodes', function (Blueprint $table): void {
            $table->dropUnique('product_barcode_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
