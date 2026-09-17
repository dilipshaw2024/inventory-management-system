<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('production_orders', 'company_id')) {
            Schema::table('production_orders', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                $table->index('company_id');
            });
        }
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('order_no');
            $table->unique(['company_id', 'external_reference'], 'production_orders_company_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropUnique('production_orders_company_external_unique');
            $table->dropColumn('external_reference');
        });
        if (Schema::hasColumn('production_orders', 'company_id')) {
            Schema::table('production_orders', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
