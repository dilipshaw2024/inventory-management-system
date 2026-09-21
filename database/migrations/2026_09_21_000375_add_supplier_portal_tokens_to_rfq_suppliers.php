<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_rfq_suppliers', function (Blueprint $table): void {
            $table->string('portal_token_hash', 64)->nullable()->after('notes');
            $table->timestamp('portal_token_expires_at')->nullable()->after('portal_token_hash');
            $table->timestamp('portal_last_accessed_at')->nullable()->after('portal_token_expires_at');
            $table->unique('portal_token_hash', 'purchase_rfq_suppliers_portal_token_unique');
            $table->index(['portal_token_expires_at', 'status'], 'purchase_rfq_suppliers_portal_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_rfq_suppliers', function (Blueprint $table): void {
            $table->dropUnique('purchase_rfq_suppliers_portal_token_unique');
            $table->dropIndex('purchase_rfq_suppliers_portal_expiry_idx');
            $table->dropColumn(['portal_token_hash', 'portal_token_expires_at', 'portal_last_accessed_at']);
        });
    }
};
