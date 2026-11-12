<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_technicians', function (Blueprint $table): void {
            $table->json('working_calendar')->nullable()->after('is_available');
        });
    }

    public function down(): void
    {
        Schema::table('service_technicians', function (Blueprint $table): void {
            $table->dropColumn('working_calendar');
        });
    }
};
