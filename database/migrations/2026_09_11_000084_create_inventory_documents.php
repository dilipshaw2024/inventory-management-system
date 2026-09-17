<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('document_no', 100);
            $table->enum('document_type', ['receipt', 'issue']);
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->date('date');
            $table->string('description', 2000)->nullable();
            $table->string('status', 30)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'document_no']);
            $table->index(['company_id', 'document_type', 'status']);
        });

        Schema::create('inventory_document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->timestamps();
            $table->index(['inventory_document_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_document_lines');
        Schema::dropIfExists('inventory_documents');
    }
};
