<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_escalations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 150);
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('approval_step')->default(1);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('required_permission', 100);
            $table->unsignedInteger('escalation_level')->default(1);
            $table->timestamp('overdue_since');
            $table->string('status', 20)->default('pending');
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgment_note')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_type', 'document_id', 'approval_step', 'user_id', 'escalation_level'], 'approval_escalation_recipient_unique');
            $table->index(['company_id', 'user_id', 'status', 'updated_at'], 'approval_escalation_recipient_status_idx');
            $table->index(['company_id', 'document_type', 'document_id'], 'approval_escalation_document_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_escalations');
    }
};
