<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->string('coverage_status', 20)->default('unknown')->after('covered');
            $table->index(['company_id', 'coverage_status']);
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'coverage_status']);
            $table->dropColumn('coverage_status');
        });
    }
};
