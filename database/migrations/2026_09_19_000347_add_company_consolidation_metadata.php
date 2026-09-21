<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignId('parent_company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            $table->string('consolidation_currency', 3)->nullable()->after('base_currency');
            $table->index(['parent_company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['parent_company_id', 'is_active']);
            $table->dropForeign(['parent_company_id']);
            $table->dropColumn(['parent_company_id', 'consolidation_currency']);
        });
    }
};
