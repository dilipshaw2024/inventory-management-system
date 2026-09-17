<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_journal_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('frequency', ['daily', 'weekly', 'monthly']);
            $table->unsignedSmallInteger('interval')->default(1);
            $table->date('starts_on');
            $table->date('next_run_on');
            $table->date('ends_on')->nullable();
            $table->json('lines');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'is_active', 'next_run_on'], 'recurring_templates_schedule_idx');
            $table->unique(['company_id', 'name']);
        });

        Schema::create('recurring_journal_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_journal_template_id')->constrained('recurring_journal_templates')->cascadeOnDelete();
            $table->date('run_date');
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['recurring_journal_template_id', 'run_date'], 'recurring_template_run_unique');
            $table->index(['company_id', 'run_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_journal_runs');
        Schema::dropIfExists('recurring_journal_templates');
    }
};
