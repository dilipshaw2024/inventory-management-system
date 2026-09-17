<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('source', 40)->default('api');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('created_lines')->default(0);
            $table->unsignedInteger('duplicate_lines')->default(0);
            $table->string('status', 24)->default('completed');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'provider', 'created_at'], 'bank_import_batches_sync_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('bank_statement_import_batches'); }
};
