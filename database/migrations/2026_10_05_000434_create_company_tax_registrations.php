<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_tax_registrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('jurisdiction', 100);
            $table->string('scheme', 40);
            $table->string('registration_number', 100);
            $table->string('legal_name')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'scheme', 'registration_number'], 'company_tax_reg_scheme_number_unique');
            $table->unique(['company_id', 'external_reference'], 'company_tax_reg_external_unique');
            $table->index(['company_id', 'jurisdiction', 'is_active'], 'company_tax_reg_jurisdiction_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_tax_registrations');
    }
};
