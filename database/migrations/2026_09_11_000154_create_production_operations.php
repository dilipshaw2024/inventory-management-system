<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('routing_operation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_center_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('operation');
            $table->decimal('planned_quantity', 18, 6)->default(0);
            $table->decimal('completed_quantity', 18, 6)->default(0);
            $table->decimal('actual_setup_minutes', 10, 2)->nullable();
            $table->decimal('actual_run_minutes', 10, 2)->nullable();
            $table->string('status', 30)->default('pending');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['production_order_id', 'sequence']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('production_operations'); }
};
