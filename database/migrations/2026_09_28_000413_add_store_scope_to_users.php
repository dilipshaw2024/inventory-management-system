<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('branch_id')->constrained('stores')->nullOnDelete();
            $table->index(['company_id', 'store_id'], 'users_company_store_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_company_store_idx');
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
