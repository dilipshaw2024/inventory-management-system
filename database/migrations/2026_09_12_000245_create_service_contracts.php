<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('contract_no', 80);
            $table->string('external_reference', 150)->nullable();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('service_assets')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('coverage_type', 30)->default('full');
            $table->unsignedInteger('response_hours')->nullable();
            $table->decimal('contract_value', 19, 6)->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->enum('status', ['active', 'expired', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'contract_no'], 'service_contracts_company_no_unique');
            $table->unique(['company_id', 'external_reference'], 'service_contracts_company_external_unique');
            $table->index(['company_id', 'customer_id', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_contracts');
    }
};
