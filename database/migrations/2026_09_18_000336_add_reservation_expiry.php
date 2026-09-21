<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->index(['company_id', 'status', 'expires_at'], 'stock_reservations_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropIndex('stock_reservations_expiry_index');
            $table->dropColumn('expires_at');
        });
    }
};
