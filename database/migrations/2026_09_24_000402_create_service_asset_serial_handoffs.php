<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_asset_serial_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_asset_id')->constrained('service_assets')->cascadeOnDelete();
            $table->foreignId('inventory_serial_id')->constrained('inventory_serials')->restrictOnDelete();
            $table->enum('action', ['installed', 'removed']);
            $table->dateTime('effective_at');
            $table->string('location', 500)->nullable();
            $table->text('notes')->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'service_asset_serial_handoffs_company_external_unique');
            $table->index(['company_id', 'service_asset_id', 'effective_at'], 'svc_handoff_company_asset_effective_idx');
            $table->index(['company_id', 'inventory_serial_id', 'effective_at'], 'svc_handoff_company_serial_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_asset_serial_handoffs');
    }
};
