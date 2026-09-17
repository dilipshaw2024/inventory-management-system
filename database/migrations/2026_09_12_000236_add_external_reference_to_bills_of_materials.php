<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills_of_materials', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('version');
            $table->unique(['company_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('bills_of_materials', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'external_reference']);
            $table->dropColumn('external_reference');
        });
    }
};
