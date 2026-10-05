<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->string('accrual_frequency', 20)->default('annual')->after('annual_entitlement');
            $table->unsignedTinyInteger('accrual_start_month')->default(1)->after('accrual_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->dropColumn(['accrual_frequency', 'accrual_start_month']);
        });
    }
};
