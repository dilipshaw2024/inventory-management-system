<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->cascadeOnDelete();
            $table->string('name', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('inventory_snapshot_id')->nullable()->constrained('inventory_reconciliation_snapshots')->nullOnDelete();
            $table->text('close_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'fiscal_year_id', 'name'], 'fiscal_period_company_year_name_unique');
            $table->index(['company_id', 'starts_on', 'ends_on', 'status'], 'fiscal_period_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
