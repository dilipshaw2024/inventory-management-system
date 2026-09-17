<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->boolean('is_final_delivery')->default(false);
            $table->string('discrepancy_status', 30)->default('none')->index();
            $table->text('discrepancy_reason')->nullable();
            $table->text('discrepancy_resolution')->nullable();
            $table->foreignId('discrepancy_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discrepancy_resolved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->dropForeign(['discrepancy_resolved_by']);
            $table->dropIndex(['discrepancy_status']);
            $table->dropColumn(['is_final_delivery', 'discrepancy_status', 'discrepancy_reason', 'discrepancy_resolution', 'discrepancy_resolved_by', 'discrepancy_resolved_at']);
        });
    }
};
