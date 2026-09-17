<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_tracking_events', function (Blueprint $table): void {
            $table->string('provider', 50)->default('generic')->after('delivery_id');
            $table->json('raw_payload')->nullable()->after('description');
        });

        Schema::table('delivery_tracking_events', function (Blueprint $table): void {
            $table->dropUnique('delivery_tracking_events_company_external_unique');
            $table->unique(['company_id', 'provider', 'external_reference'], 'delivery_tracking_events_company_provider_external_unique');
            $table->index(['company_id', 'provider', 'updated_at'], 'delivery_tracking_events_provider_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_tracking_events', function (Blueprint $table): void {
            $table->dropUnique('delivery_tracking_events_company_provider_external_unique');
            $table->dropIndex('delivery_tracking_events_provider_sync_idx');
            $table->unique(['company_id', 'external_reference'], 'delivery_tracking_events_company_external_unique');
            $table->dropColumn(['provider', 'raw_payload']);
        });
    }
};
