<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->string('leave_policy', 30)->default('ignore')->after('attendance_policy');
        });
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->decimal('leave_days', 10, 3)->default(0)->after('attendance_deduction');
            $table->decimal('leave_deduction', 18, 6)->default(0)->after('leave_days');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropColumn(['leave_days', 'leave_deduction']);
        });
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->dropColumn('leave_policy');
        });
    }
};
