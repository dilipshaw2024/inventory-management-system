<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('method', 20)->default('cash')->after('paid_status');
            $table->index(['company_id', 'pos_session_id', 'method'], 'payments_company_pos_method_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_company_pos_method_idx');
            $table->dropColumn('method');
        });
    }
};
