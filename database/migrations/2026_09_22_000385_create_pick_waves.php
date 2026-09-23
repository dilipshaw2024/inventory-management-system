<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pick_waves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('wave_no', 80)->unique();
            $table->string('external_reference', 150)->nullable();
            $table->date('wave_date');
            $table->enum('status', ['planned', 'released', 'in_progress', 'completed', 'cancelled'])->default('planned');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference']);
            $table->index(['company_id', 'status', 'wave_date']);
        });

        Schema::create('pick_wave_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pick_wave_id')->constrained('pick_waves')->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['pick_wave_id', 'delivery_id']);
            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pick_wave_deliveries');
        Schema::dropIfExists('pick_waves');
    }
};
