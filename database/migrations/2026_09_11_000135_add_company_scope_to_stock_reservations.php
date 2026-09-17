<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('stock_reservations', 'company_id')) {
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
        }
        if (!DB::selectOne('SHOW INDEX FROM stock_reservations WHERE Key_name = ?', ['stock_reservations_company_id_status_index'])) {
            Schema::table('stock_reservations', function (Blueprint $table): void {
                $table->index(['company_id', 'status'], 'stock_reservations_company_id_status_index');
            });
        }
        DB::statement('UPDATE stock_reservations r INNER JOIN products p ON p.id = r.product_id SET r.company_id = p.company_id WHERE r.company_id IS NULL AND p.company_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex('stock_reservations_company_id_status_index');
            $table->dropColumn('company_id');
        });
    }
};
