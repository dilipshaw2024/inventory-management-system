<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_escalations', function (Blueprint $table): void {
            $table->timestamp('superseded_at')->nullable()->after('acknowledgment_note');
            $table->index(['company_id', 'document_type', 'document_id', 'approval_step', 'status'], 'approval_escalation_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::table('approval_escalations', function (Blueprint $table): void {
            $table->dropIndex('approval_escalation_lifecycle_idx');
            $table->dropColumn('superseded_at');
        });
    }
};
