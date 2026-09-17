<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('tax_rate_id')->nullable()->after('tax_rate')->constrained('tax_rates')->nullOnDelete();
            $table->index(['company_id', 'tax_rate_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['tax_rate_id']);
            $table->dropIndex(['company_id', 'tax_rate_id']);
            $table->dropColumn('tax_rate_id');
        });
    }
};
