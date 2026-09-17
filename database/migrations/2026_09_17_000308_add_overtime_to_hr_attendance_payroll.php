<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->string('overtime_policy', 20)->default('ignore')->after('attendance_policy');
            $table->decimal('overtime_multiplier', 8, 4)->default(1.5)->after('overtime_policy');
        });
        Schema::table('hr_attendance', function (Blueprint $table): void {
            $table->unsignedInteger('scheduled_minutes')->nullable()->after('worked_minutes');
            $table->unsignedInteger('overtime_minutes')->default(0)->after('scheduled_minutes');
        });
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->unsignedInteger('overtime_minutes')->default(0)->after('attendance_deduction');
            $table->decimal('overtime_amount', 18, 6)->default(0)->after('overtime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void { $table->dropColumn(['overtime_minutes', 'overtime_amount']); });
        Schema::table('hr_attendance', function (Blueprint $table): void { $table->dropColumn(['scheduled_minutes', 'overtime_minutes']); });
        Schema::table('hr_pay_runs', function (Blueprint $table): void { $table->dropColumn(['overtime_policy', 'overtime_multiplier']); });
    }
};
