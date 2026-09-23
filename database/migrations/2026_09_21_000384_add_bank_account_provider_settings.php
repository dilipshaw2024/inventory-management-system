<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->string('provider', 50)->default('generic')->after('currency_code');
            $table->text('connection_config')->nullable()->after('provider');
            $table->timestamp('last_synced_at')->nullable()->after('is_active');
            $table->string('last_sync_status', 20)->nullable()->after('last_synced_at');
            $table->text('last_sync_error')->nullable()->after('last_sync_status');
            $table->index(['company_id', 'provider', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'provider', 'is_active']);
            $table->dropColumn(['provider', 'connection_config', 'last_synced_at', 'last_sync_status', 'last_sync_error']);
        });
    }
};
