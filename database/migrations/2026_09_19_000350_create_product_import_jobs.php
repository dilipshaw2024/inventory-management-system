<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_reference', 150)->nullable();
            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('status', 30)->default('pending');
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference']);
            $table->index(['company_id', 'status', 'created_at']);
        });
    }

    public function down(): void { Schema::dropIfExists('product_import_jobs'); }
};
