<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->dateTime('scheduled_start_at')->nullable()->after('scheduled_date');
            $table->dateTime('scheduled_end_at')->nullable()->after('scheduled_start_at');
            $table->decimal('estimated_hours', 8, 2)->nullable()->after('scheduled_end_at');
            $table->index(['company_id', 'assigned_to', 'scheduled_start_at', 'scheduled_end_at'], 'maintenance_dispatch_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->dropIndex('maintenance_dispatch_window_idx');
            $table->dropColumn(['scheduled_start_at', 'scheduled_end_at', 'estimated_hours']);
        });
    }
};
