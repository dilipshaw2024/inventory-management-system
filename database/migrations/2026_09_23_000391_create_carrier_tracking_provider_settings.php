<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_tracking_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 80);
            $table->text('connection_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'provider']);
            $table->index(['company_id', 'provider', 'is_active'], 'carrier_provider_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_tracking_provider_settings');
    }
};
