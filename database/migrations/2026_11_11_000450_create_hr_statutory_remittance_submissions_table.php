<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_statutory_remittance_submissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('provider', 80);
            $table->string('package_hash', 64);
            $table->json('filters')->nullable();
            $table->json('summary')->nullable();
            $table->string('status', 30)->default('submitted');
            $table->string('provider_reference', 190)->nullable();
            $table->json('provider_response')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider', 'package_hash'], 'hr_stat_remit_company_provider_hash_unique');
            $table->index(['company_id', 'status', 'submitted_at'], 'hr_stat_remit_company_status_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_statutory_remittance_submissions');
    }
};
