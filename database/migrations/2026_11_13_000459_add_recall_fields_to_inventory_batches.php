<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->timestamp('recalled_at')->nullable()->after('warranty_until');
            $table->unsignedBigInteger('recalled_by')->nullable()->after('recalled_at');
            $table->string('recall_reference', 150)->nullable()->after('recalled_by');
            $table->text('recall_reason')->nullable()->after('recall_reference');
            $table->timestamp('recall_released_at')->nullable()->after('recall_reason');
            $table->unsignedBigInteger('recall_released_by')->nullable()->after('recall_released_at');
            $table->index(['recalled_at', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->dropIndex(['recalled_at', 'product_id']);
            $table->dropColumn([
                'recalled_at', 'recalled_by', 'recall_reference', 'recall_reason',
                'recall_released_at', 'recall_released_by',
            ]);
        });
    }
};
