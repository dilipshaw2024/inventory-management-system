<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->enum('inspection_status', ['not_required', 'pending', 'passed', 'failed'])->default('not_required')->after('status');
            $table->text('inspection_notes')->nullable()->after('inspection_status');
            $table->foreignId('inspected_by')->nullable()->after('inspection_notes')->constrained('users')->nullOnDelete();
            $table->timestamp('inspected_at')->nullable()->after('inspected_by');
            $table->index(['inspection_status', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropForeign(['inspected_by']);
            $table->dropIndex(['inspection_status', 'status']);
            $table->dropColumn(['inspection_status', 'inspection_notes', 'inspected_by', 'inspected_at']);
        });
    }
};
