<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->string('batch_no', 100)->nullable()->after('unit_cost');
            $table->text('serial_numbers')->nullable()->after('batch_no');
            $table->date('manufacturing_date')->nullable()->after('serial_numbers');
            $table->date('expiry_date')->nullable()->after('manufacturing_date');
            $table->date('best_before_date')->nullable()->after('expiry_date');
            $table->date('warranty_until')->nullable()->after('best_before_date');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->dropColumn(['batch_no', 'serial_numbers', 'manufacturing_date', 'expiry_date', 'best_before_date', 'warranty_until']);
        });
    }
};
