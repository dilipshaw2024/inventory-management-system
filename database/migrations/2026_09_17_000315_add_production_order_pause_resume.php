<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','released','in_progress','paused','completed','cancelled') NOT NULL DEFAULT 'draft'");
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->string('pause_reason', 2000)->nullable()->after('status');
            $table->foreignId('paused_by')->nullable()->after('pause_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('paused_at')->nullable()->after('paused_by');
            $table->string('paused_from_status', 30)->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropForeign(['paused_by']);
            $table->dropColumn(['pause_reason', 'paused_by', 'paused_at', 'paused_from_status']);
        });
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','released','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
