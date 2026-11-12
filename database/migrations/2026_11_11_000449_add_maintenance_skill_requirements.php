<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->json('required_skills')->nullable()->after('estimated_hours');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->dropColumn('required_skills');
        });
    }
};
