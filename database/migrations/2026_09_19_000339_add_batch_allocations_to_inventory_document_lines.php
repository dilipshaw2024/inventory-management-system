<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_document_lines', function (Blueprint $table): void {
            $table->json('batch_allocations')->nullable()->after('batch_no');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_document_lines', fn (Blueprint $table) => $table->dropColumn('batch_allocations'));
    }
};
