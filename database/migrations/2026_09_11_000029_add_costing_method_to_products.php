<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->enum('costing_method', ['fifo', 'weighted_average', 'standard'])->default('fifo')->after('tracking_type');
            $table->decimal('standard_cost', 19, 6)->nullable()->after('costing_method');
        });
    }
    public function down(): void { Schema::table('products', function (Blueprint $table): void { $table->dropColumn(['costing_method', 'standard_cost']); }); }
};
