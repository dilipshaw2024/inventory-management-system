<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->index(['company_id', 'status']);
        });
    }
    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'status']);
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
