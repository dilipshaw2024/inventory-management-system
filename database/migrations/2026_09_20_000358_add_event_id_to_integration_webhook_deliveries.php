<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_webhook_deliveries', function (Blueprint $table): void {
            $table->string('event_id', 191)->nullable()->after('event_type');
            $table->unique(['subscription_id', 'event_id'], 'webhook_subscription_event_unique');
        });
    }

    public function down(): void
    {
        Schema::table('integration_webhook_deliveries', function (Blueprint $table): void {
            $table->dropUnique('webhook_subscription_event_unique');
            $table->dropColumn('event_id');
        });
    }
};
