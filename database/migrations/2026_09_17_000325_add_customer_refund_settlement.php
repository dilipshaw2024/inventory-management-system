<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_refunds', function (Blueprint $table): void {
            $table->enum('settlement_status', ['pending', 'settled'])->default('pending')->after('status');
            $table->string('settlement_reference', 150)->nullable()->after('reference');
            $table->timestamp('settled_at')->nullable()->after('approved_at');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
            $table->unique(['company_id', 'settlement_reference'], 'customer_refunds_company_settlement_unique');
            $table->index(['company_id', 'settlement_status', 'settled_at'], 'customer_refunds_settlement_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_refunds', function (Blueprint $table): void {
            $table->dropUnique('customer_refunds_company_settlement_unique');
            $table->dropIndex('customer_refunds_settlement_status_idx');
            $table->dropForeign(['settled_by']);
            $table->dropColumn(['settlement_status', 'settlement_reference', 'settled_at', 'settled_by']);
        });
    }
};
