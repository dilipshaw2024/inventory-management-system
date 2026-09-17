<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->decimal('settled_amount', 19, 6)->default(0)->after('claim_amount');
            $table->string('settlement_reference', 150)->nullable()->after('settled_amount');
            $table->foreignId('settled_by')->nullable()->after('settlement_reference')->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('settled_by');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_claims', function (Blueprint $table): void {
            $table->dropForeign(['settled_by']);
            $table->dropColumn(['settled_amount', 'settlement_reference', 'settled_by', 'settled_at']);
        });
    }
};
