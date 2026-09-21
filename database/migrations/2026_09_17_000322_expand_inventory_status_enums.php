<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE inventory_status_balances MODIFY status ENUM('blocked','quarantine','damaged','scrap') NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY from_status ENUM('available','blocked','quarantine','damaged') NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status ENUM('blocked','quarantine','damaged','scrap') NOT NULL");
    }

    public function down(): void
    {
        if (DB::table('inventory_status_balances')->where('status', 'blocked')->exists() || DB::table('inventory_status_transfers')->where(fn ($query) => $query->where('from_status', 'blocked')->orWhere('to_status', 'blocked'))->exists()) {
            throw new \RuntimeException('Cannot restore the previous inventory status enums while blocked records exist.');
        }
        DB::statement("ALTER TABLE inventory_status_balances MODIFY status ENUM('quarantine','damaged','scrap') NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY from_status ENUM('available','quarantine','damaged') NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status ENUM('quarantine','damaged','scrap') NOT NULL");
    }
};
