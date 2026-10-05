<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->boolean('counts_working_days')->default(false)->after('is_paid');
        });
    }

    public function down(): void
    {
        Schema::table('hr_leave_types', function (Blueprint $table): void {
            $table->dropColumn('counts_working_days');
        });
    }
};
