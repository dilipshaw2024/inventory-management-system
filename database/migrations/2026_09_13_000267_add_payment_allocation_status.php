<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments', 'supplier_payments'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('allocation_status', 30)->default('unallocated')->after('company_id');
                $table->index(['company_id', 'allocation_status']);
            });
        }

        DB::statement("UPDATE payments p LEFT JOIN (SELECT payment_id, COALESCE(SUM(amount), 0) AS allocated FROM customer_payment_allocations WHERE voided_at IS NULL GROUP BY payment_id) a ON a.payment_id = p.id SET p.allocation_status = CASE WHEN COALESCE(a.allocated, 0) <= 0 THEN 'unallocated' WHEN COALESCE(a.allocated, 0) + 0.000001 >= COALESCE(p.paid_amount, 0) THEN 'fully_allocated' ELSE 'partially_allocated' END");
        DB::statement("UPDATE supplier_payments p LEFT JOIN (SELECT supplier_payment_id, COALESCE(SUM(amount), 0) AS allocated FROM supplier_payment_allocations WHERE voided_at IS NULL GROUP BY supplier_payment_id) a ON a.supplier_payment_id = p.id SET p.allocation_status = CASE WHEN COALESCE(a.allocated, 0) <= 0 THEN 'unallocated' WHEN COALESCE(a.allocated, 0) + 0.000001 >= COALESCE(p.amount, 0) THEN 'fully_allocated' ELSE 'partially_allocated' END");
    }

    public function down(): void
    {
        foreach (['payments', 'supplier_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex($tableName.'_company_id_allocation_status_index');
                $table->dropColumn('allocation_status');
            });
        }
    }
};
