<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','released','in_progress','paused','completed','closed','cancelled') NOT NULL DEFAULT 'draft'");
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->string('close_reason', 2000)->nullable()->after('cancelled_at');
            $table->foreignId('closed_by')->nullable()->after('close_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropForeign(['closed_by']);
            $table->dropColumn(['close_reason', 'closed_by', 'closed_at']);
        });
        DB::statement("ALTER TABLE production_orders MODIFY status ENUM('draft','released','in_progress','paused','completed','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
