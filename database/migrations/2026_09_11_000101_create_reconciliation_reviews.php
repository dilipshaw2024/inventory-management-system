<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('as_of_date');
            $table->enum('status', ['reconciled', 'variance', 'needs_mapping']);
            $table->json('rows');
            $table->text('notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->index(['company_id', 'as_of_date']);
        });
    }
    public function down(): void { Schema::dropIfExists('reconciliation_reviews'); }
};
