<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_retention_holds', function (Blueprint $table): void {
            $table->string('release_reason')->nullable()->after('released_by');
        });
    }

    public function down(): void
    {
        Schema::table('data_retention_holds', function (Blueprint $table): void {
            $table->dropColumn('release_reason');
        });
    }
};
