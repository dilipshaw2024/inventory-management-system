<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('service_maintenance_schedules', 'meter_interval')) {
            Schema::table('service_maintenance_schedules', function (Blueprint $table): void {
                $table->decimal('meter_interval', 19, 6)->nullable();
            });
        }
        if (!Schema::hasColumn('service_maintenance_schedules', 'next_meter_due')) {
            Schema::table('service_maintenance_schedules', function (Blueprint $table): void {
                $table->decimal('next_meter_due', 19, 6)->nullable();
            });
        }
        if (!DB::selectOne('SHOW INDEX FROM service_maintenance_schedules WHERE Key_name = ?', ['maintenance_meter_due_idx'])) {
            Schema::table('service_maintenance_schedules', function (Blueprint $table): void {
                $table->index(['asset_id', 'meter_interval', 'next_meter_due'], 'maintenance_meter_due_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_maintenance_schedules', function (Blueprint $table): void {
            $table->dropIndex(['asset_id', 'meter_interval', 'next_meter_due']);
            $table->dropColumn(['meter_interval', 'next_meter_due']);
        });
    }
};
