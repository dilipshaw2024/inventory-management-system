<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_cost_histories', function (Blueprint $table): void {
            $table->string('source_type', 80)->nullable()->after('reason');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_reference', 150)->nullable()->after('source_id');
            $table->index(['source_type', 'source_id'], 'product_cost_history_source_idx');
            $table->index(['product_id', 'source_reference'], 'product_cost_history_reference_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_cost_histories', function (Blueprint $table): void {
            $table->dropIndex('product_cost_history_source_idx');
            $table->dropIndex('product_cost_history_reference_idx');
            $table->dropColumn(['source_type', 'source_id', 'source_reference']);
        });
    }
};
