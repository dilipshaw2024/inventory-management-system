<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_rules', function (Blueprint $table): void {
            $table->boolean('is_statutory')->default(false)->after('value');
            $table->string('statutory_authority', 120)->nullable()->after('is_statutory');
            $table->string('remittance_mapping_key', 80)->nullable()->after('statutory_authority');
        });
        Schema::table('hr_payslips', function (Blueprint $table): void {
            $table->json('statutory_deductions')->nullable()->after('deductions');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payslips', function (Blueprint $table): void { $table->dropColumn('statutory_deductions'); });
        Schema::table('hr_payroll_rules', function (Blueprint $table): void { $table->dropColumn(['is_statutory', 'statutory_authority', 'remittance_mapping_key']); });
    }
};
