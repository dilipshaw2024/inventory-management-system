<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_revaluation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference')->nullable();
            $table->date('as_of_date');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->decimal('total_variance', 19, 6)->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'cost_revaluation_company_external_unique');
            $table->index(['company_id', 'as_of_date', 'status'], 'reval_runs_company_date_status_idx');
        });

        Schema::create('inventory_cost_revaluation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revaluation_run_id')->constrained('inventory_cost_revaluation_runs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('cost_layer_id')->constrained('inventory_cost_layers')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('old_unit_cost', 19, 6);
            $table->decimal('new_unit_cost', 19, 6);
            $table->decimal('variance_amount', 19, 6);
            $table->timestamps();
            $table->unique(['revaluation_run_id', 'cost_layer_id'], 'cost_revaluation_run_layer_unique');
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_revaluation_lines');
        Schema::dropIfExists('inventory_cost_revaluation_runs');
    }
};
