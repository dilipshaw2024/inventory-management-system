<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('from_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('factor', 24, 12);
            $table->unsignedTinyInteger('decimal_places')->default(6);
            $table->boolean('is_active')->default(true);
            $table->string('external_reference', 150)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'from_unit_id', 'to_unit_id'], 'unit_conversions_scope_pair_unique');
            $table->unique(['company_id', 'external_reference'], 'unit_conversions_company_external_unique');
            $table->index(['from_unit_id', 'to_unit_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_conversions');
    }
};
