<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->string('bom_version', 50)->nullable()->after('bom_id');
            $table->index(['company_id', 'bom_version']);
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'bom_version']);
            $table->dropColumn('bom_version');
        });
    }
};
