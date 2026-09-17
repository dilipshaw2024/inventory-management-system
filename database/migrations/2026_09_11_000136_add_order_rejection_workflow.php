<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('draft','submitted','approved','partially_received','received','cancelled','rejected') NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE sales_orders MODIFY status ENUM('draft','submitted','approved','partially_delivered','delivered','cancelled','rejected') NOT NULL DEFAULT 'draft'");
        foreach (['purchase_orders', 'sales_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->text('rejection_reason')->nullable()->after('cancellation_reason');
                $table->foreignId('rejected_by')->nullable()->after('cancelled_by')->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable()->after('cancelled_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_orders', 'sales_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['rejected_by']);
                $table->dropColumn(['rejection_reason', 'rejected_by', 'rejected_at']);
            });
        }
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('draft','submitted','approved','partially_received','received','cancelled') NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE sales_orders MODIFY status ENUM('draft','submitted','approved','partially_delivered','delivered','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
