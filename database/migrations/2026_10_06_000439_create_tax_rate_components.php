<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rate_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->decimal('rate', 8, 4);
            $table->string('component_type', 40)->default('other');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tax_rate_id', 'code'], 'tax_rate_components_rate_code_unique');
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rate_components');
    }
};
