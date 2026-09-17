<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropUnique(['external_reference']);
            $table->unique(['company_id', 'external_reference'], 'inventory_adjustments_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropUnique('inventory_adjustments_company_external_unique');
            $table->unique('external_reference');
        });
    }
};
