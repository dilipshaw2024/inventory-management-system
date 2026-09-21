<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_spare_parts', function (Blueprint $table): void {
            $table->foreignId('supplier_id')->nullable()->after('product_id')->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_part_no', 100)->nullable()->after('supplier_id');
            $table->unsignedInteger('lead_time_days')->nullable()->after('supplier_part_no');
            $table->decimal('supplier_unit_cost', 19, 6)->nullable()->after('lead_time_days');
            $table->char('supplier_currency', 3)->nullable()->after('supplier_unit_cost');
            $table->boolean('preferred_supplier')->default(false)->after('supplier_currency');
            $table->index(['company_id', 'supplier_id'], 'asset_spare_parts_company_supplier_idx');
        });
    }

    public function down(): void
    {
        Schema::table('asset_spare_parts', function (Blueprint $table): void {
            $table->dropIndex('asset_spare_parts_company_supplier_idx');
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['supplier_id', 'supplier_part_no', 'lead_time_days', 'supplier_unit_cost', 'supplier_currency', 'preferred_supplier']);
        });
    }
};
