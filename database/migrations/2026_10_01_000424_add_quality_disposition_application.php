<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->foreignId('inventory_status_transfer_id')->nullable()->after('disposition')->constrained('inventory_status_transfers')->nullOnDelete();
            $table->timestamp('disposition_applied_at')->nullable()->after('completed_at');
            $table->index(['company_id', 'disposition', 'disposition_applied_at'], 'quality_disposition_application_idx');
        });
    }

    public function down(): void
    {
        Schema::table('quality_inspections', function (Blueprint $table): void {
            $table->dropIndex('quality_disposition_application_idx');
            $table->dropForeign(['inventory_status_transfer_id']);
            $table->dropColumn(['inventory_status_transfer_id', 'disposition_applied_at']);
        });
    }
};
