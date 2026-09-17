<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfer_lines', function (Blueprint $table): void {
            $table->decimal('received_quantity', 18, 6)->default(0)->after('quantity');
        });
        Schema::table('inventory_transfers', function (Blueprint $table): void {
            $table->text('receiving_note')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', fn (Blueprint $table) => $table->dropColumn('receiving_note'));
        Schema::table('inventory_transfer_lines', fn (Blueprint $table) => $table->dropColumn('received_quantity'));
    }
};
