<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_conversions', function (Blueprint $table): void {
            $table->dropUnique('unit_conversions_scope_pair_unique');
            $table->date('effective_from')->default('1900-01-01')->after('factor');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->unique(['company_id', 'from_unit_id', 'to_unit_id', 'effective_from'], 'unit_conversions_effective_pair_unique');
            $table->index(['company_id', 'from_unit_id', 'to_unit_id', 'effective_from', 'effective_to'], 'unit_conversions_effective_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('unit_conversions', function (Blueprint $table): void {
            $table->dropUnique('unit_conversions_effective_pair_unique');
            $table->dropIndex('unit_conversions_effective_lookup_index');
            $table->dropColumn(['effective_from', 'effective_to']);
            $table->unique(['company_id', 'from_unit_id', 'to_unit_id'], 'unit_conversions_scope_pair_unique');
        });
    }
};
