<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->foreignId('labor_journal_entry_id')->nullable()->after('labor_cost')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('labor_journal_entry_id');
        });
    }
};
