<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('supplier_claims')
            ->select('company_id', 'settlement_reference')
            ->whereNotNull('settlement_reference')
            ->groupBy('company_id', 'settlement_reference')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException('Cannot enforce unique supplier-claim settlement references until duplicate historical references are resolved.');
        }

        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->unique(['company_id', 'settlement_reference'], 'supplier_claims_company_settlement_unique');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->dropUnique('supplier_claims_company_settlement_unique');
        });
    }
};
