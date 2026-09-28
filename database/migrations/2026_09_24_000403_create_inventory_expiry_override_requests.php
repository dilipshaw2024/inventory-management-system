<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_expiry_override_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_document_id')->constrained('inventory_documents')->cascadeOnDelete();
            $table->string('scope', 20);
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->dateTime('consumed_at')->nullable();
            $table->foreignId('consumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'inventory_document_id', 'status'], 'inv_expiry_override_lookup_idx');
            $table->index(['company_id', 'status', 'updated_at'], 'inv_expiry_override_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_expiry_override_requests');
    }
};
