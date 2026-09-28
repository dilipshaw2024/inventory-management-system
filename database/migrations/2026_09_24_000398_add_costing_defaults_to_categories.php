<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->string('default_costing_method', 30)->nullable()->after('required_attribute_ids');
            $table->decimal('default_standard_cost', 19, 6)->nullable()->after('default_costing_method');
            $table->index(['company_id', 'default_costing_method'], 'categories_company_costing_default_idx');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex('categories_company_costing_default_idx');
            $table->dropColumn(['default_costing_method', 'default_standard_cost']);
        });
    }
};
