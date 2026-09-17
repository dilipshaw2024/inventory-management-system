<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_status_balances') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_status_balances MODIFY status VARCHAR(30) NOT NULL");
        }
        if (Schema::hasTable('inventory_status_transfers') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_status_transfers MODIFY from_status VARCHAR(30) NOT NULL");
            DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status VARCHAR(30) NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_status_transfers') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_status_transfers MODIFY from_status ENUM('available','quarantine','damaged') NOT NULL");
            DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status ENUM('quarantine','damaged','scrap') NOT NULL");
        }
        if (Schema::hasTable('inventory_status_balances') && DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE inventory_status_balances MODIFY status ENUM('quarantine','damaged','scrap') NOT NULL");
        }
    }
};
