<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('id');
            $table->unique(['product_id', 'location_id', 'external_reference'], 'replenishment_policy_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->dropUnique('replenishment_policy_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
