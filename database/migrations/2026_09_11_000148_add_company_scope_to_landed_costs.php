<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landed_costs', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->index('company_id');
        });

        DB::table('landed_costs')->orderBy('id')->get(['id', 'goods_receipt_id'])->each(function (object $cost): void {
            $companyId = DB::table('goods_receipts')->where('id', $cost->goods_receipt_id)->value('company_id');
            if ($companyId) DB::table('landed_costs')->where('id', $cost->id)->update(['company_id' => $companyId]);
        });
    }

    public function down(): void
    {
        Schema::table('landed_costs', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
