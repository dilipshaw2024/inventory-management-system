<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->decimal('settlement_posted_amount', 19, 6)->default(0)->after('settled_amount');
            $table->foreignId('settlement_journal_id')->nullable()->after('settlement_posted_amount')->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->dropForeign(['settlement_journal_id']);
            $table->dropColumn(['settlement_posted_amount', 'settlement_journal_id']);
        });
    }
};
