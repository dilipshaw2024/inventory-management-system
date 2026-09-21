<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_asset_meter_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->constrained('service_assets')->cascadeOnDelete();
            $table->string('external_reference', 150);
            $table->decimal('meter_value', 19, 6);
            $table->timestamp('occurred_at');
            $table->string('source', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'service_meter_company_reference_unique');
            $table->index(['asset_id', 'occurred_at'], 'service_meter_asset_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_asset_meter_readings');
    }
};
