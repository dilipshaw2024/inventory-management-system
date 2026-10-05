<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->decimal('carry_forward_days', 10, 3)->default(0)->after('annual_entitlement');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->dropColumn('carry_forward_days');
        });
    }
};
