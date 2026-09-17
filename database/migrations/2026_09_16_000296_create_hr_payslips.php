<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pay_run_id')->constrained('hr_pay_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->decimal('gross_amount', 18, 6)->default(0);
            $table->decimal('deduction_amount', 18, 6)->default(0);
            $table->decimal('net_amount', 18, 6)->default(0);
            $table->string('currency_code', 3)->nullable();
            $table->json('deductions')->nullable();
            $table->timestamps();
            $table->unique(['pay_run_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
    }
};
