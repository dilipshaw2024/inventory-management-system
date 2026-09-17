<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer_payment_allocations', 'supplier_payment_allocations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->timestamp('voided_at')->nullable()->after('allocated_at');
                $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
                $table->string('void_reason', 2000)->nullable()->after('voided_by');
                $table->index(['company_id', 'voided_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['customer_payment_allocations', 'supplier_payment_allocations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['voided_by']);
                $table->dropIndex([$tableName.'_company_id_voided_at_index']);
                $table->dropColumn(['voided_at', 'voided_by', 'void_reason']);
            });
        }
    }
};
