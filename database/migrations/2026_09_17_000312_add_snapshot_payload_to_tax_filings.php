<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->json('snapshot_payload')->nullable()->after('net_tax');
            $table->char('snapshot_hash', 64)->nullable()->after('snapshot_payload');
            $table->index(['company_id', 'snapshot_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->dropIndex('tax_filings_company_id_snapshot_hash_index');
            $table->dropColumn(['snapshot_payload', 'snapshot_hash']);
        });
    }
};
