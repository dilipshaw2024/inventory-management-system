<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('inventory_reconciliation_snapshots')) {
            Schema::create('inventory_reconciliation_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->date('as_of_date');
                $table->enum('status', ['balanced', 'variance'])->default('balanced');
                $table->unsignedBigInteger('movement_count')->default(0);
                $table->unsignedBigInteger('line_count')->default(0);
                $table->decimal('total_quantity', 24, 6)->default(0);
                $table->json('rows');
                $table->char('snapshot_hash', 64);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['company_id', 'as_of_date'], 'inventory_snapshot_company_date_unique');
            });
        }
        if (!DB::selectOne('SHOW INDEX FROM inventory_reconciliation_snapshots WHERE Key_name = ?', ['inventory_snapshot_status_date_idx'])) {
            Schema::table('inventory_reconciliation_snapshots', function (Blueprint $table): void {
                $table->index(['company_id', 'status', 'as_of_date'], 'inventory_snapshot_status_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reconciliation_snapshots');
    }
};
