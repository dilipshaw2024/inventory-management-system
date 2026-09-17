<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('transfer_no');
            $table->index(['company_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_status_transfers', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
