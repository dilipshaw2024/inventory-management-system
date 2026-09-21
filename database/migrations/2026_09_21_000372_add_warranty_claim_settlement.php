<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->string('settlement_status', 20)->default('unsettled')->after('resolved_at');
            $table->string('settlement_reference', 150)->nullable()->after('settlement_status');
            $table->decimal('settlement_amount', 19, 6)->nullable()->after('settlement_reference');
            $table->string('settlement_currency', 3)->nullable()->after('settlement_amount');
            $table->timestamp('settled_at')->nullable()->after('settlement_currency');
            $table->foreignId('settled_by')->nullable()->after('settled_at')->constrained('users')->nullOnDelete();
            $table->index(['company_id', 'settlement_status', 'settled_at'], 'warranty_claims_settlement_idx');
            $table->unique(['company_id', 'settlement_reference'], 'warranty_claims_company_settlement_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropUnique('warranty_claims_company_settlement_ref_unique');
            $table->dropIndex('warranty_claims_settlement_idx');
            $table->dropConstrainedForeignId('settled_by');
            $table->dropColumn(['settlement_status', 'settlement_reference', 'settlement_amount', 'settlement_currency', 'settled_at']);
        });
    }
};
