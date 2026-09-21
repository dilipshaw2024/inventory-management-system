<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_retention_purge_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('policy_id')->constrained('data_retention_policies')->cascadeOnDelete();
            $table->timestamp('cutoff_at');
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'policy_id', 'status'], 'retention_purge_request_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_purge_requests');
    }
};
