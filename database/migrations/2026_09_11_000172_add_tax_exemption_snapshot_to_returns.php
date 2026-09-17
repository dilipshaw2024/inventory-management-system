<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->boolean('tax_exempt')->default(false)->after('supplier_id');
            $table->string('tax_exemption_number')->nullable()->after('tax_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_returns', function (Blueprint $table): void {
            $table->dropColumn(['tax_exempt', 'tax_exemption_number']);
        });
    }
};
