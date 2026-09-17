<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('suppliers', 'planning_calendar')) {
            Schema::table('suppliers', function (Blueprint $table): void {
                $table->json('planning_calendar')->nullable()->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('suppliers', 'planning_calendar')) {
            Schema::table('suppliers', function (Blueprint $table): void { $table->dropColumn('planning_calendar'); });
        }
    }
};
