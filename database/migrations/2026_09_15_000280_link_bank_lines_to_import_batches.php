<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->foreignId('import_batch_id')->nullable()->after('provider')->constrained('bank_statement_import_batches')->nullOnDelete();
            $table->index(['company_id', 'import_batch_id'], 'bank_lines_import_batch_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropForeign(['import_batch_id']);
            $table->dropIndex('bank_lines_import_batch_idx');
            $table->dropColumn('import_batch_id');
        });
    }
};
