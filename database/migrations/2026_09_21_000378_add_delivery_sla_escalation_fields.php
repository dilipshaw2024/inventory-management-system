<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->timestamp('sla_breached_at')->nullable()->after('delivered_at');
            $table->unsignedTinyInteger('sla_escalation_level')->default(0)->after('sla_breached_at');
            $table->timestamp('last_sla_escalated_at')->nullable()->after('sla_escalation_level');
            $table->index(['company_id', 'sla_breached_at', 'last_sla_escalated_at'], 'deliveries_sla_alert_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropIndex('deliveries_sla_alert_idx');
            $table->dropColumn(['sla_breached_at', 'sla_escalation_level', 'last_sla_escalated_at']);
        });
    }
};
