<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->after('branch_id')->constrained('categories')->nullOnDelete();
            $table->index(['company_id', 'branch_id', 'category_id', 'document_type', 'is_active'], 'approval_policies_context_index');
        });
    }

    public function down(): void
    {
        Schema::table('approval_policies', function (Blueprint $table): void {
            $table->dropIndex('approval_policies_context_index');
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['category_id']);
            $table->dropColumn(['branch_id', 'category_id']);
        });
    }
};
