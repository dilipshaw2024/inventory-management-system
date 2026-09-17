<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->string('variance_status', 20)->nullable()->after('receiving_note');
            $table->text('variance_reason')->nullable()->after('variance_status');
            $table->foreignId('variance_resolved_by')->nullable()->after('variance_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('variance_resolved_at')->nullable()->after('variance_resolved_by');
            $table->index(['company_id', 'variance_status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->dropForeign(['variance_resolved_by']);
            $table->dropIndex(['company_id', 'variance_status']);
            $table->dropColumn(['variance_status', 'variance_reason', 'variance_resolved_by', 'variance_resolved_at']);
        });
    }
};
