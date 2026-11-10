<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('language_code', 35);
            $table->string('name', 255);
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'language_code'], 'product_translation_language_unique');
            $table->unique(['company_id', 'external_reference'], 'product_translation_external_reference_unique');
            $table->index(['company_id', 'language_code', 'updated_at'], 'product_translation_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_translations');
    }
};
