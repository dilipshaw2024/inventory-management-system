<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills_of_materials', function (Blueprint $table): void {
            $table->string('version', 50)->default('1')->after('code');
            $table->index(['company_id', 'product_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::table('bills_of_materials', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'product_id', 'version']);
            $table->dropColumn('version');
        });
    }
};
