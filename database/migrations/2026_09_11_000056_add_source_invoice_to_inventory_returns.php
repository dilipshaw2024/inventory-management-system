<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void { Schema::table('inventory_returns', fn (Blueprint $table) => $table->foreignId('source_invoice_id')->nullable()->after('customer_id')->constrained('invoices')->nullOnDelete()); }
    public function down(): void { Schema::table('inventory_returns', fn (Blueprint $table) => $table->dropForeign(['source_invoice_id'])->dropColumn('source_invoice_id')); }
};
