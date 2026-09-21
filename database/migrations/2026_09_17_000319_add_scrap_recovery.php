<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_scrap_records', function (Blueprint $table): void {
            $table->foreignId('recovery_product_id')->nullable()->after('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('recovery_quantity', 18, 6)->nullable()->after('recovery_product_id');
            $table->decimal('recovery_unit_cost', 19, 6)->nullable()->after('recovery_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('production_scrap_records', function (Blueprint $table): void {
            $table->dropForeign(['recovery_product_id']);
            $table->dropColumn(['recovery_product_id', 'recovery_quantity', 'recovery_unit_cost']);
        });
    }
};
