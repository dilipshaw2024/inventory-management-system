<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->index('company_id');
            $table->string('external_reference', 150)->nullable()->after('return_no');
            $table->unique(['company_id', 'external_reference'], 'inventory_returns_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropUnique('inventory_returns_company_external_unique');
            $table->dropColumn('external_reference');
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
