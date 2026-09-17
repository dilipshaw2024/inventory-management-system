<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void { Schema::table('invoices', fn (Blueprint $table) => $table->foreignId('promotion_id')->nullable()->after('total_amount')->constrained('promotions')->nullOnDelete()); }
    public function down(): void { Schema::table('invoices', fn (Blueprint $table) => $table->dropForeign(['promotion_id'])->dropColumn('promotion_id')); }
};
