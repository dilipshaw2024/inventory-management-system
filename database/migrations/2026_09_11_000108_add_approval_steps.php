<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('approval_policies', 'approval_step')) {
            Schema::table('approval_policies', function (Blueprint $table): void {
                $table->unsignedInteger('approval_step')->default(1)->after('required_permission');
            });
        }
        if (!DB::selectOne('SHOW INDEX FROM approval_policies WHERE Key_name = ?', ['approval_policy_step_scope_idx'])) {
            Schema::table('approval_policies', function (Blueprint $table): void {
                $table->index(['company_id', 'document_type', 'approval_step', 'is_active'], 'approval_policy_step_scope_idx');
            });
        }
        if (Schema::hasTable('approval_actions')) return;
        Schema::create('approval_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('document_type', 150);
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('approval_step');
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
            $table->unique(['company_id', 'document_type', 'document_id', 'approval_step'], 'approval_actions_document_step_unique');
            $table->index(['company_id', 'document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->dropIndex('approval_policy_step_scope_idx');
            $table->dropColumn('approval_step');
        });
    }
};
