<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->string('disposition_status', 30)->default('available')->after('inspection_status');
            $table->index(['company_id', 'disposition_status'], 'inventory_returns_disposition_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropIndex('inventory_returns_disposition_idx');
            $table->dropColumn('disposition_status');
        });
    }
};
