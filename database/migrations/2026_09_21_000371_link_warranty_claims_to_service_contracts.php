<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->foreignId('contract_id')->nullable()->after('asset_id')->constrained('service_contracts')->nullOnDelete();
            $table->index(['company_id', 'contract_id', 'received_at'], 'warranty_claims_contract_received_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropIndex('warranty_claims_contract_received_idx');
            $table->dropConstrainedForeignId('contract_id');
        });
    }
};
