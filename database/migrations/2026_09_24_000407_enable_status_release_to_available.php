<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status ENUM('available','blocked','quarantine','damaged','scrap') NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('inventory_status_transfers')->where('to_status', 'available')->update(['to_status' => 'quarantine']);
            DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status ENUM('blocked','quarantine','damaged','scrap') NOT NULL");
        }
    }
};
