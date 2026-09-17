<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_maintenance_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained('service_assets')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('frequency_days');
            $table->date('next_due');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['is_active', 'next_due']);
        });
    }
    public function down(): void { Schema::dropIfExists('service_maintenance_schedules'); }
};
