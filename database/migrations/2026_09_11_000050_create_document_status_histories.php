<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type');
            $table->unsignedBigInteger('document_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('action')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('changed_at');
            $table->index(['document_type', 'document_id', 'changed_at'], 'document_status_history_lookup_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('document_status_histories'); }
};
