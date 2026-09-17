<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->string('attendance_policy', 30)->default('ignore')->after('pay_date');
        });

        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->unsignedInteger('scheduled_days')->default(0)->after('employee_id');
            $table->unsignedInteger('absent_days')->default(0)->after('scheduled_days');
            $table->decimal('attendance_deduction', 18, 6)->default(0)->after('absent_days');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->dropColumn(['scheduled_days', 'absent_days', 'attendance_deduction']);
        });
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->dropColumn('attendance_policy');
        });
    }
};
