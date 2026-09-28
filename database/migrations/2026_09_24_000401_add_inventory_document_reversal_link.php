<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('reversal_of_id')->nullable()->after('production_order_id')->constrained('inventory_documents')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->after('reversal_of_id')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->text('reversal_reason')->nullable()->after('reversed_at');
            $table->index(['company_id', 'reversal_of_id'], 'inventory_documents_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropIndex('inventory_documents_reversal_idx');
            $table->dropConstrainedForeignId('reversal_of_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
