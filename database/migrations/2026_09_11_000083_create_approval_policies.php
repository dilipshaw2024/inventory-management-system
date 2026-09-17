<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('document_type', 150);
            $table->decimal('min_amount', 18, 4)->nullable();
            $table->decimal('max_amount', 18, 4)->nullable();
            $table->string('required_permission', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'document_type', 'is_active']);
        });
    }

    public function down(): void { Schema::dropIfExists('approval_policies'); }
};
