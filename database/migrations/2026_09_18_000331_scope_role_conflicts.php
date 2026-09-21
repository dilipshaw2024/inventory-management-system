<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_conflicts', function (Blueprint $table): void {
            // The legacy composite unique index is also the leftmost index
            // supporting role_id's foreign key on MySQL. Add replacement
            // indexes before removing it.
            $table->index('role_id', 'role_conflicts_role_id_idx');
            $table->index('conflicting_role_id', 'role_conflicts_conflicting_role_id_idx');
            $table->dropUnique(['role_id', 'conflicting_role_id']);
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('reason');
            $table->unique(['company_id', 'role_id', 'conflicting_role_id'], 'role_conflicts_company_pair_unique');
            $table->index(['company_id', 'is_active'], 'role_conflicts_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('role_conflicts', function (Blueprint $table): void {
            $table->dropUnique('role_conflicts_company_pair_unique');
            $table->dropForeign(['company_id']);
            $table->dropIndex('role_conflicts_company_active_idx');
            $table->dropColumn(['company_id', 'is_active']);
            $table->unique(['role_id', 'conflicting_role_id']);
            $table->dropIndex('role_conflicts_role_id_idx');
            $table->dropIndex('role_conflicts_conflicting_role_id_idx');
        });
    }
};
