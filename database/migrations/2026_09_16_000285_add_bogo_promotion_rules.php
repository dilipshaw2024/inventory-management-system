<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table): void {
            $table->decimal('buy_quantity', 18, 6)->nullable()->after('discount_value');
            $table->decimal('get_quantity', 18, 6)->nullable()->after('buy_quantity');
        });
        DB::statement("ALTER TABLE promotions MODIFY type ENUM('percentage', 'fixed', 'bogo') NOT NULL DEFAULT 'percentage'");
    }

    public function down(): void
    {
        DB::statement("UPDATE promotions SET type = 'fixed' WHERE type = 'bogo'");
        DB::statement("ALTER TABLE promotions MODIFY type ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage'");
        Schema::table('promotions', function (Blueprint $table): void { $table->dropColumn(['buy_quantity', 'get_quantity']); });
    }
};
