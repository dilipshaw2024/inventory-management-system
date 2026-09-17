<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->string('warehouse_type', 30)->default('standard')->after('code');
            $table->index(['branch_id', 'warehouse_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'warehouse_type', 'is_active']);
            $table->dropColumn('warehouse_type');
        });
    }
};
