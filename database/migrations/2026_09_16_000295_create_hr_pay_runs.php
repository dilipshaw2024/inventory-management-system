<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_pay_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('run_no', 60);
            $table->string('external_reference', 150)->nullable();
            $table->string('frequency', 20);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('pay_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('gross_total', 18, 6)->default(0);
            $table->decimal('deduction_total', 18, 6)->default(0);
            $table->decimal('net_total', 18, 6)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'run_no']);
            $table->unique(['company_id', 'external_reference']);
            $table->index(['company_id', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_pay_runs');
    }
};
