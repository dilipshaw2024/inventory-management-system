<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('invoice_type', 20)->default('goods')->after('invoice_no');
            $table->foreignId('maintenance_order_id')->nullable()->after('store_id')->constrained('maintenance_orders')->nullOnDelete();
            $table->unique('maintenance_order_id', 'invoices_maintenance_order_unique');
            $table->index(['company_id', 'invoice_type']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_maintenance_order_unique');
            $table->dropConstrainedForeignId('maintenance_order_id');
            $table->dropColumn(['invoice_type']);
        });
    }
};
