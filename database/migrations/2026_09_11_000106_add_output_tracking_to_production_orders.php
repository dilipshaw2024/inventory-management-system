<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->string('output_batch_no')->nullable()->after('completed_quantity');
            $table->text('output_serial_numbers')->nullable()->after('output_batch_no');
            $table->date('output_manufacturing_date')->nullable()->after('output_serial_numbers');
            $table->date('output_expiry_date')->nullable()->after('output_manufacturing_date');
            $table->date('output_best_before_date')->nullable()->after('output_expiry_date');
            $table->date('output_warranty_until')->nullable()->after('output_best_before_date');
        });
    }
    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropColumn(['output_batch_no', 'output_serial_numbers', 'output_manufacturing_date', 'output_expiry_date', 'output_best_before_date', 'output_warranty_until']);
        });
    }
};
