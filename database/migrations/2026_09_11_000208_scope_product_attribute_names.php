<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table): void {
            $table->dropUnique('product_attributes_name_unique');
            $table->unique(['company_id', 'name'], 'product_attributes_company_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table): void {
            $table->dropUnique('product_attributes_company_name_unique');
            $table->unique('name', 'product_attributes_name_unique');
        });
    }
};
