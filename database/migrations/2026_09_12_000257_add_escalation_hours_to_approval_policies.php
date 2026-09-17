<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('approval_policies', 'escalation_after_hours')) {
            Schema::table('approval_policies', function (Blueprint $table): void {
                $table->unsignedInteger('escalation_after_hours')->nullable()->after('required_permission');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('approval_policies', 'escalation_after_hours')) {
            Schema::table('approval_policies', function (Blueprint $table): void {
                $table->dropColumn('escalation_after_hours');
            });
        }
    }
};
