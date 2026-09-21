<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_rfqs', function (Blueprint $table): void {
            $table->foreignId('purchase_requisition_id')->nullable()->after('company_id')->constrained('purchase_requisitions')->nullOnDelete();
            $table->index(['company_id', 'purchase_requisition_id'], 'purchase_rfqs_requisition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_rfqs', function (Blueprint $table): void {
            $table->dropForeign(['purchase_requisition_id']);
            $table->dropIndex('purchase_rfqs_requisition_idx');
            $table->dropColumn('purchase_requisition_id');
        });
    }
};
