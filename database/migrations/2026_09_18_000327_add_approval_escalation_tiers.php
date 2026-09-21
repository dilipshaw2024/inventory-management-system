<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->json('escalation_permissions')->nullable()->after('escalation_after_hours');
            $table->unsignedTinyInteger('max_escalation_level')->default(3)->after('escalation_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->dropColumn(['escalation_permissions', 'max_escalation_level']);
        });
    }
};
