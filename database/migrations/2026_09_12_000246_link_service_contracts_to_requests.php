<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->foreignId('contract_id')->nullable()->after('customer_id')->constrained('service_contracts')->nullOnDelete();
            $table->timestamp('response_due_at')->nullable()->after('assigned_at');
            $table->index(['company_id', 'contract_id', 'response_due_at']);
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'contract_id', 'response_due_at']);
            $table->dropConstrainedForeignId('contract_id');
            $table->dropColumn('response_due_at');
        });
    }
};
