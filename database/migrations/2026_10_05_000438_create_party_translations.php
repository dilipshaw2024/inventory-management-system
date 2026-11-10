<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('party_type', 20);
            $table->unsignedBigInteger('party_id');
            $table->string('language_code', 35);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'party_type', 'party_id', 'language_code'], 'party_translation_language_unique');
            $table->unique(['company_id', 'external_reference'], 'party_translation_external_reference_unique');
            $table->index(['company_id', 'party_type', 'party_id', 'updated_at'], 'party_translation_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_translations');
    }
};
