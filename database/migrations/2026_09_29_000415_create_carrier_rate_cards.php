<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_rate_cards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference', 150)->nullable();
            $table->string('carrier', 255);
            $table->string('service_code', 80);
            $table->string('origin_zone', 80)->nullable();
            $table->string('destination_zone', 80)->nullable();
            $table->decimal('min_weight_kg', 18, 6)->nullable();
            $table->decimal('max_weight_kg', 18, 6)->nullable();
            $table->decimal('base_amount', 19, 6)->default(0);
            $table->decimal('per_kg_amount', 19, 6)->default(0);
            $table->string('currency_code', 3);
            $table->unsignedInteger('transit_days')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'carrier_rate_cards_company_external_unique');
            $table->index(['company_id', 'carrier', 'service_code', 'is_active'], 'carrier_rate_cards_lookup_idx');
            $table->index(['company_id', 'valid_from', 'valid_until'], 'carrier_rate_cards_validity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_rate_cards');
    }
};
