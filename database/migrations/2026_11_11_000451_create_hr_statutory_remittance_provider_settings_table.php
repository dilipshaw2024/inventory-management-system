<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_statutory_remittance_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('provider', 80);
            $table->text('connection_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'provider'], 'hr_stat_remit_settings_company_provider_unique');
            $table->index(['company_id', 'is_active'], 'hr_stat_remit_settings_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_statutory_remittance_provider_settings');
    }
};
