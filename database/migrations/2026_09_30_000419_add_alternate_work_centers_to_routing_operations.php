<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routing_operations', function (Blueprint $table): void {
            $table->json('alternate_work_center_ids')->nullable()->after('work_center_id');
        });
    }

    public function down(): void
    {
        Schema::table('routing_operations', function (Blueprint $table): void {
            $table->dropColumn('alternate_work_center_ids');
        });
    }
};
