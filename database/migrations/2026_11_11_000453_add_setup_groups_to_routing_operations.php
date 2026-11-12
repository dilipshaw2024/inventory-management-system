<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routing_operations', function (Blueprint $table): void {
            $table->string('setup_group', 100)->nullable()->after('operation');
            $table->index(['company_id', 'setup_group'], 'routing_operations_company_setup_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('routing_operations', function (Blueprint $table): void {
            $table->dropIndex('routing_operations_company_setup_group_idx');
            $table->dropColumn('setup_group');
        });
    }
};
