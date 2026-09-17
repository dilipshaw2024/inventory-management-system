<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_retention_archives', function (Blueprint $table): void {
            $table->id();
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->timestamp('archived_at');
            $table->timestamps();
            $table->unique(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_archives');
    }
};
