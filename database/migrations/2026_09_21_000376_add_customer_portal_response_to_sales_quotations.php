<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->string('customer_portal_token_hash', 64)->nullable()->after('description');
            $table->timestamp('customer_portal_token_expires_at')->nullable()->after('customer_portal_token_hash');
            $table->timestamp('customer_portal_last_accessed_at')->nullable()->after('customer_portal_token_expires_at');
            $table->string('customer_response_status', 20)->default('pending')->after('customer_portal_last_accessed_at');
            $table->timestamp('customer_responded_at')->nullable()->after('customer_response_status');
            $table->text('customer_response_notes')->nullable()->after('customer_responded_at');
            $table->unique('customer_portal_token_hash', 'sales_quotations_customer_portal_token_unique');
            $table->index(['company_id', 'customer_response_status'], 'sales_quotations_customer_response_idx');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotations', function (Blueprint $table): void {
            $table->dropUnique('sales_quotations_customer_portal_token_unique');
            $table->dropIndex('sales_quotations_customer_response_idx');
            $table->dropColumn(['customer_portal_token_hash', 'customer_portal_token_expires_at', 'customer_portal_last_accessed_at', 'customer_response_status', 'customer_responded_at', 'customer_response_notes']);
        });
    }
};
