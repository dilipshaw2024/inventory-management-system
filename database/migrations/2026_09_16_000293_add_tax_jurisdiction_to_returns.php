<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->string('tax_jurisdiction', 100)->nullable()->after('tax_exemption_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_returns', fn (Blueprint $table) => $table->dropColumn('tax_jurisdiction'));
    }
};
