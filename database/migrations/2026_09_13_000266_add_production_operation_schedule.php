<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_operations', function (Blueprint $table): void {
            $table->timestamp('scheduled_start_at')->nullable()->after('completed_at');
            $table->timestamp('scheduled_end_at')->nullable()->after('scheduled_start_at');
            $table->string('schedule_status', 30)->default('unscheduled')->after('scheduled_end_at');
            $table->index(['work_center_id', 'scheduled_start_at', 'scheduled_end_at'], 'production_operation_schedule_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('production_operations', function (Blueprint $table): void {
            $table->dropIndex('production_operation_schedule_window_idx');
            $table->dropColumn(['scheduled_start_at', 'scheduled_end_at', 'schedule_status']);
        });
    }
};
