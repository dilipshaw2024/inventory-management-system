<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_rules', function (Blueprint $table): void {
            $table->string('employer_calculation', 20)->nullable()->after('remittance_mapping_key');
            $table->decimal('employer_value', 18, 6)->default(0)->after('employer_calculation');
            $table->string('employer_mapping_key', 80)->nullable()->after('employer_value');
        });
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->decimal('employer_contribution_total', 18, 6)->default(0)->after('deduction_total');
        });
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->decimal('employer_contribution_total', 18, 6)->default(0)->after('overtime_amount');
            $table->json('employer_contributions')->nullable()->after('statutory_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void { $table->dropColumn(['employer_contribution_total', 'employer_contributions']); });
        Schema::table('hr_pay_runs', function (Blueprint $table): void { $table->dropColumn('employer_contribution_total'); });
        Schema::table('hr_payroll_rules', function (Blueprint $table): void { $table->dropColumn(['employer_calculation', 'employer_value', 'employer_mapping_key']); });
    }
};
