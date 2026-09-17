<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'service_assets' => 'asset_no',
            'service_requests' => 'request_no',
            'maintenance_orders' => 'order_no',
            'service_technicians' => 'employee_code',
        ];
        foreach ($columns as $tableName => $column) {
            $targetIndex = $tableName.'_company_'.$column.'_unique';
            $legacyIndex = $tableName.'_'.$column.'_unique';
            if (!DB::selectOne('SHOW INDEX FROM '.$tableName.' WHERE Key_name = ?', [$targetIndex])) {
                Schema::table($tableName, function (Blueprint $table) use ($targetIndex, $column): void {
                    $table->unique(['company_id', $column], $targetIndex);
                });
            }
            if (DB::selectOne('SHOW INDEX FROM '.$tableName.' WHERE Key_name = ?', [$legacyIndex])) {
                Schema::table($tableName, function (Blueprint $table) use ($legacyIndex): void {
                    $table->dropUnique($legacyIndex);
                });
            }
        }
    }

    public function down(): void
    {
        $columns = [
            'service_assets' => 'asset_no',
            'service_requests' => 'request_no',
            'maintenance_orders' => 'order_no',
            'service_technicians' => 'employee_code',
        ];
        foreach ($columns as $tableName => $column) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $column): void {
                $table->dropUnique($tableName.'_company_'.$column.'_unique');
                $table->unique($column, $tableName.'_'.$column.'_unique');
            });
        }
    }
};
