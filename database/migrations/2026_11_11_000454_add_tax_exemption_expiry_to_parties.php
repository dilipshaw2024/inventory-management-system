<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->date('tax_exemption_expires_at')->nullable()->after('tax_exemption_number');
                $table->index(['company_id', 'tax_exemption_expires_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex([$tableName.'_company_id_tax_exemption_expires_at_index']);
                $table->dropColumn('tax_exemption_expires_at');
            });
        }
    }
};
