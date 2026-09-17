<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_ownership_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('service_assets')->cascadeOnDelete();
            $table->foreignId('previous_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('new_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->date('effective_date');
            $table->string('external_reference', 150)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'asset_ownership_company_external_unique');
            $table->index(['asset_id', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_ownership_transfers');
    }
};
