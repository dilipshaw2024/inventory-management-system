<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->json('escalation_user_ids')->nullable()->after('escalation_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->dropColumn('escalation_user_ids');
        });
    }
};
