<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->index(['company_id', 'is_active'], 'replenishment_policies_company_active_index');
        });

        // Preserve legacy rows as shared when neither owner can identify a
        // tenant, while assigning all determinable policies explicitly.
        DB::statement('UPDATE inventory_replenishment_policies p LEFT JOIN products pr ON pr.id = p.product_id LEFT JOIN inventory_locations l ON l.id = p.location_id LEFT JOIN warehouses w ON w.id = l.warehouse_id LEFT JOIN branches b ON b.id = w.branch_id SET p.company_id = COALESCE(pr.company_id, b.company_id) WHERE p.company_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('inventory_replenishment_policies', function (Blueprint $table): void {
            $table->dropIndex('replenishment_policies_company_active_index');
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
