<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->json('document_types')->nullable();
            // DATETIME keeps the required window explicit and remains compatible
            // with MySQL 5.7, where a non-null TIMESTAMP without a default can
            // be rejected by the legacy implicit-default rules.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'delegate_id', 'is_active', 'starts_at', 'ends_at'], 'approval_delegations_scope_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('approval_delegations'); }
};
