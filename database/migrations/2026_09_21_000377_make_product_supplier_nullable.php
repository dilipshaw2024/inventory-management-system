<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE `products` MODIFY `supplier_id` INT NULL');
        elseif (DB::getDriverName() === 'pgsql') DB::statement('ALTER TABLE products ALTER COLUMN supplier_id DROP NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE `products` MODIFY `supplier_id` INT NOT NULL');
        elseif (DB::getDriverName() === 'pgsql') DB::statement('ALTER TABLE products ALTER COLUMN supplier_id SET NOT NULL');
    }
};
