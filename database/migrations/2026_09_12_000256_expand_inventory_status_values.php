<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        // Status values are workflow data, not a closed enum. VARCHAR keeps
        // existing installations compatible and permits future dispositions.
        DB::statement("ALTER TABLE inventory_status_balances MODIFY status VARCHAR(30) NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY from_status VARCHAR(30) NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status VARCHAR(30) NOT NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') return;

        DB::statement("ALTER TABLE inventory_status_balances MODIFY status ENUM('quarantine','damaged','scrap') NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY from_status ENUM('available','quarantine','damaged') NOT NULL");
        DB::statement("ALTER TABLE inventory_status_transfers MODIFY to_status ENUM('quarantine','damaged','scrap') NOT NULL");
    }
};
