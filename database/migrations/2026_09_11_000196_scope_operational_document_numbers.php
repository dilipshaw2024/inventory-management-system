<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->documents() as [$tableName, $column]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $tableName): void {
                $table->dropUnique([$column]);
                $table->unique(['company_id', $column], $tableName.'_company_'.$column.'_unique');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->documents() as [$tableName, $column]) {
            Schema::table($tableName, function (Blueprint $table) use ($column, $tableName): void {
                $table->dropUnique($tableName.'_company_'.$column.'_unique');
                $table->unique($column);
            });
        }
    }

    /** @return array<int, array{0: string, 1: string}> */
    private function documents(): array
    {
        return [
            ['purchase_orders', 'po_no'],
            ['goods_receipts', 'grn_no'],
            ['sales_orders', 'order_no'],
            ['deliveries', 'delivery_no'],
            ['inventory_adjustments', 'adjustment_no'],
            ['inventory_transfers', 'transfer_no'],
            ['inventory_status_transfers', 'transfer_no'],
            ['stock_counts', 'count_no'],
            ['purchase_invoices', 'invoice_no'],
            ['production_orders', 'order_no'],
            ['service_assets', 'asset_no'],
            ['service_requests', 'request_no'],
            ['maintenance_orders', 'order_no'],
            ['inventory_returns', 'return_no'],
        ];
    }
};
