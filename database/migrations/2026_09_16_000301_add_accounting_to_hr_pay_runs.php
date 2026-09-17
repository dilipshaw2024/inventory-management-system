<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->foreignId('journal_entry_id')->nullable()->after('net_total')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_pay_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
