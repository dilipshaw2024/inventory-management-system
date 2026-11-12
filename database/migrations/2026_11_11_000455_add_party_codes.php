<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            if (! Schema::hasColumn($tableName, 'code')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('code', 100)->nullable()->after('name');
                    $table->index(['company_id', 'code']);
                });
            }

            $prefix = $tableName === 'customers' ? 'CUS-' : 'SUP-';
            DB::table($tableName)
                ->whereNull('code')
                ->orderBy('id')
                ->eachById(function (object $party) use ($tableName, $prefix): void {
                    DB::table($tableName)->where('id', $party->id)->update(['code' => $prefix.$party->id]);
                });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            if (Schema::hasColumn($tableName, 'code')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropIndex(['company_id', 'code']);
                    $table->dropColumn('code');
                });
            }
        }
    }
};
