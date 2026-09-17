<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->modifyColumns('DECIMAL(19, 6)', 'DECIMAL(19, 6) NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new \RuntimeException('Migration 000117 currently requires the MySQL-compatible decimal conversion path.');
        }
        $this->statements([
            'ALTER TABLE products MODIFY quantity DOUBLE NOT NULL DEFAULT 0',
            'ALTER TABLE purchases MODIFY buying_qty DOUBLE NOT NULL, MODIFY unit_price DOUBLE NOT NULL, MODIFY buying_price DOUBLE NOT NULL',
            'ALTER TABLE invoice_details MODIFY selling_qty DOUBLE NULL, MODIFY unit_price DOUBLE NULL, MODIFY selling_price DOUBLE NULL',
            'ALTER TABLE payments MODIFY paid_amount DOUBLE NULL, MODIFY due_amount DOUBLE NULL, MODIFY total_amount DOUBLE NULL, MODIFY discount_amount DOUBLE NULL',
            'ALTER TABLE payment_details MODIFY current_paid_amount DOUBLE NULL',
        ]);
    }

    private function modifyColumns(string $decimal, string $productDefinition): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new \RuntimeException('Migration 000117 currently requires the MySQL-compatible decimal conversion path.');
        }
        $this->statements([
            'ALTER TABLE products MODIFY quantity '.$productDefinition,
            'ALTER TABLE purchases MODIFY buying_qty '.$decimal.' NOT NULL, MODIFY unit_price '.$decimal.' NOT NULL, MODIFY buying_price '.$decimal.' NOT NULL',
            'ALTER TABLE invoice_details MODIFY selling_qty '.$decimal.' NULL, MODIFY unit_price '.$decimal.' NULL, MODIFY selling_price '.$decimal.' NULL',
            'ALTER TABLE payments MODIFY paid_amount '.$decimal.' NULL, MODIFY due_amount '.$decimal.' NULL, MODIFY total_amount '.$decimal.' NULL, MODIFY discount_amount '.$decimal.' NULL',
            'ALTER TABLE payment_details MODIFY current_paid_amount '.$decimal.' NULL',
        ]);
    }

    private function statements(array $statements): void
    {
        foreach ($statements as $statement) DB::statement($statement);
    }
};
