<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->string('accounting_status', 20)->default('not_posted')->after('settled_by');
            $table->string('accounting_mode', 30)->nullable()->after('accounting_status');
            $table->foreignId('journal_entry_id')->nullable()->after('accounting_mode')->constrained('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'accounting_status'], 'warranty_claims_accounting_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warranty_claims', function (Blueprint $table): void {
            $table->dropIndex('warranty_claims_accounting_status_idx');
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropColumn(['accounting_status', 'accounting_mode']);
        });
    }
};
