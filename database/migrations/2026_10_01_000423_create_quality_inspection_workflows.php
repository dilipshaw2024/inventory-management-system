<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_inspection_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('code', 80);
            $table->string('name', 180);
            $table->string('inspection_type', 40)->default('receiving');
            $table->decimal('sampling_percent', 8, 4)->default(100);
            $table->string('external_reference', 150)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code'], 'quality_plans_company_code_unique');
            $table->unique(['company_id', 'external_reference'], 'quality_plans_company_external_unique');
            $table->index(['company_id', 'product_id', 'inspection_type', 'is_active'], 'quality_plans_scope_idx');
        });

        Schema::create('quality_inspection_plan_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('quality_inspection_plans')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('characteristic', 180);
            $table->string('data_type', 20)->default('text');
            $table->string('unit', 40)->nullable();
            $table->decimal('target_value', 20, 8)->nullable();
            $table->decimal('minimum_value', 20, 8)->nullable();
            $table->decimal('maximum_value', 20, 8)->nullable();
            $table->boolean('is_required')->default(true);
            $table->timestamps();
            $table->unique(['plan_id', 'sequence'], 'quality_plan_lines_plan_sequence_unique');
            $table->index(['plan_id', 'is_required'], 'quality_plan_lines_required_idx');
        });

        Schema::create('quality_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('quality_inspection_plans')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('inspection_no', 100);
            $table->string('external_reference', 150)->nullable();
            $table->string('source_type', 80)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('quantity', 20, 8)->default(1);
            $table->string('status', 30)->default('pending');
            $table->string('disposition', 30)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'inspection_no'], 'quality_inspections_company_no_unique');
            $table->unique(['company_id', 'external_reference'], 'quality_inspections_company_external_unique');
            $table->index(['company_id', 'status', 'product_id', 'updated_at'], 'quality_inspections_feed_idx');
            $table->index(['source_type', 'source_id'], 'quality_inspections_source_idx');
        });

        Schema::create('quality_inspection_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('quality_inspections')->cascadeOnDelete();
            $table->foreignId('plan_line_id')->constrained('quality_inspection_plan_lines')->restrictOnDelete();
            $table->decimal('value_numeric', 20, 8)->nullable();
            $table->text('value_text')->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['inspection_id', 'plan_line_id'], 'quality_results_inspection_line_unique');
            $table->index(['inspection_id', 'status'], 'quality_results_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_inspection_results');
        Schema::dropIfExists('quality_inspections');
        Schema::dropIfExists('quality_inspection_plan_lines');
        Schema::dropIfExists('quality_inspection_plans');
    }
};
