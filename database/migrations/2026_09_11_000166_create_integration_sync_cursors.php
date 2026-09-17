<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_sync_cursors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('principal_key', 120);
            $table->string('feed', 100);
            $table->dateTime('cursor_updated_at');
            $table->unsignedBigInteger('cursor_id');
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['company_id', 'principal_key', 'feed']);
            $table->index(['company_id', 'feed', 'cursor_updated_at', 'cursor_id'], 'sync_cursor_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_cursors');
    }
};
