<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_centers', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->decimal('capacity_hours_per_day', 10, 2)->default(8);
            $table->decimal('labor_rate', 19, 6)->default(0);
            $table->decimal('machine_rate', 19, 6)->default(0);
            $table->json('calendar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('routings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bom_id')->constrained('bills_of_materials')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['bom_id', 'code']);
        });

        Schema::create('routing_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('routing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_center_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('operation');
            $table->decimal('setup_minutes', 10, 2)->default(0);
            $table->decimal('run_minutes', 10, 2)->default(0);
            $table->timestamps();
            $table->unique(['routing_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_operations');
        Schema::dropIfExists('routings');
        Schema::dropIfExists('work_centers');
    }
};
