<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('proof_type', 30)->default('recipient');
            $table->string('external_reference', 150)->nullable();
            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient_contact', 100)->nullable();
            $table->timestamp('delivered_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('attachment_id')->nullable()->constrained('document_attachments')->nullOnDelete();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'delivery_proofs_company_external_unique');
            $table->index(['company_id', 'delivery_id', 'delivered_at'], 'delivery_proofs_delivery_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_proofs');
    }
};
