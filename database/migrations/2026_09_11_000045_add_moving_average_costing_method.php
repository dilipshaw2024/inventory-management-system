<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'costing_method')) return;
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE products MODIFY costing_method VARCHAR(30) NOT NULL DEFAULT 'fifo'");
        if (DB::getDriverName() === 'pgsql') DB::statement("ALTER TABLE products ALTER COLUMN costing_method TYPE VARCHAR(30) USING costing_method::text");
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'costing_method')) return;
        if (DB::getDriverName() === 'mysql') DB::statement("ALTER TABLE products MODIFY costing_method ENUM('fifo','weighted_average','standard') NOT NULL DEFAULT 'fifo'");
    }
};
