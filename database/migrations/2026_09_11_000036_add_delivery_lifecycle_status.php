<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('fulfillment_status', 24)->default('pending')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void { $table->dropColumn('fulfillment_status'); });
    }
};
