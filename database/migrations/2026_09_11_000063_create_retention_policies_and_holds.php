<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('record_type');
            $table->unsignedInteger('retention_days');
            $table->boolean('archive_enabled')->default(true);
            $table->boolean('purge_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('data_retention_holds', function (Blueprint $table): void {
            $table->id();
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->string('reason');
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['record_type', 'record_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_retention_holds');
        Schema::dropIfExists('data_retention_policies');
    }
};
