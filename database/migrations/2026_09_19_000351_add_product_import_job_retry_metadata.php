<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_import_jobs', function (Blueprint $table): void {
            $table->unsignedTinyInteger('attempts')->default(0)->after('status');
            $table->unsignedTinyInteger('max_attempts')->default(3)->after('attempts');
            $table->timestamp('next_attempt_at')->nullable()->after('completed_at');
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('product_import_jobs', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropColumn(['attempts', 'max_attempts', 'next_attempt_at']);
        });
    }
};
