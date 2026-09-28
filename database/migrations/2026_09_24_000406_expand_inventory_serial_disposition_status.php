<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE inventory_serials MODIFY status ENUM('available','reserved','issued','returned','scrapped','quarantine','damaged') NOT NULL DEFAULT 'available'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('inventory_serials')->where('status', 'damaged')->update(['status' => 'quarantine']);
            DB::statement("ALTER TABLE inventory_serials MODIFY status ENUM('available','reserved','issued','returned','scrapped','quarantine') NOT NULL DEFAULT 'available'");
        }
    }
};
