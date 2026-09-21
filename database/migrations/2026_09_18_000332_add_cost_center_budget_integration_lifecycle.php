<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_center_budgets', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('id');
            $table->boolean('is_active')->default(true)->after('budget_amount');
            $table->unique(['company_id', 'external_reference'], 'cost_center_budgets_company_external_unique');
            $table->index(['company_id', 'is_active'], 'cost_center_budgets_company_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cost_center_budgets', function (Blueprint $table): void {
            $table->dropUnique('cost_center_budgets_company_external_unique');
            $table->dropIndex('cost_center_budgets_company_active_idx');
            $table->dropColumn(['external_reference', 'is_active']);
        });
    }
};
