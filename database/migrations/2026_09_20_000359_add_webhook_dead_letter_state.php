<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_deliveries', function (Blueprint $table): void {
            $table->timestamp('dead_lettered_at')->nullable()->after('delivered_at');
            $table->index(['status', 'dead_lettered_at'], 'webhook_status_dead_letter_idx');
        });
        DB::table('integration_webhook_deliveries')
            ->where('status', 'failed')
            ->where('attempts', '>=', 5)
            ->update(['status' => 'dead_letter', 'dead_lettered_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('integration_webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex('webhook_status_dead_letter_idx');
            $table->dropColumn('dead_lettered_at');
        });
    }
};
