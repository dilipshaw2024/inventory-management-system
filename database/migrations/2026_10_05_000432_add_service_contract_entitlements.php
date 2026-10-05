<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_contracts', function (Blueprint $table): void {
            $table->unsignedInteger('request_limit')->nullable()->after('response_hours');
            $table->unsignedInteger('requests_used')->default(0)->after('request_limit');
        });
    }

    public function down(): void
    {
        Schema::table('service_contracts', function (Blueprint $table): void {
            $table->dropColumn(['request_limit', 'requests_used']);
        });
    }
};
