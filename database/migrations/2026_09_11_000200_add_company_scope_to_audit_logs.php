<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('audit_logs', 'company_id')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                $table->index(['company_id', 'created_at']);
            });
        }

        DB::table('audit_logs as audit_logs')
            ->join('users', 'users.id', '=', 'audit_logs.user_id')
            ->whereNull('audit_logs.company_id')
            ->whereNotNull('users.company_id')
            ->update(['audit_logs.company_id' => DB::raw('users.company_id')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('audit_logs', 'company_id')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id', 'created_at']);
                $table->dropColumn('company_id');
            });
        }
    }
};
