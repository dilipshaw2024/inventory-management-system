<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_attributes', 'product_attribute_values'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['company_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['product_attribute_values', 'product_attributes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id', 'created_at']);
                $table->dropColumn('company_id');
            });
        }
    }
};
