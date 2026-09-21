<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_lines', function (Blueprint $table): void {
            $table->foreignId('uom_id')->nullable()->after('component_product_id')->constrained('units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bom_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('uom_id');
        });
    }
};
