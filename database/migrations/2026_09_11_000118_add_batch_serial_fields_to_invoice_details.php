<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_details', function (Blueprint $table): void {
            $table->foreignId('batch_id')->nullable()->after('product_id')->constrained('inventory_batches')->nullOnDelete();
            $table->string('batch_no')->nullable()->after('batch_id');
            $table->text('serial_numbers')->nullable()->after('batch_no');
            $table->index(['product_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invoice_details', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['product_id', 'batch_id']);
            $table->dropColumn(['batch_id', 'batch_no', 'serial_numbers']);
        });
    }
};
