<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('previous_hash', 64)->nullable()->after('user_agent');
            $table->string('integrity_hash', 64)->nullable()->after('previous_hash');
            $table->index(['company_id', 'id', 'integrity_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'id', 'integrity_hash']);
            $table->dropColumn(['previous_hash', 'integrity_hash']);
        });
    }
};
