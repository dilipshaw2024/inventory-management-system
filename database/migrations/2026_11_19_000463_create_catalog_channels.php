<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('currency_code', 3)->nullable();
            $table->string('locale', 35)->nullable();
            $table->json('settings')->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'catalog_channels_company_code_unique');
            $table->unique(['company_id', 'external_reference'], 'catalog_channels_company_external_unique');
            $table->index(['company_id', 'is_active'], 'catalog_channels_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_channels');
    }
};
