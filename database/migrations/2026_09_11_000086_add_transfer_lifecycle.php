<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->foreignId('dispatched_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable()->after('dispatched_by');
            $table->foreignId('received_by')->nullable()->after('dispatched_at')->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('received_by');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_transfers MODIFY status VARCHAR(30) NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->dropForeign(['dispatched_by']);
            $table->dropForeign(['received_by']);
            $table->dropColumn(['dispatched_by', 'dispatched_at', 'received_by', 'received_at']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_transfers MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");
        }
    }
};
