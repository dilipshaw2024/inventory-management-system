<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->after('company_id')->constrained('categories')->nullOnDelete();
            $table->string('code', 50)->nullable()->unique()->after('name');
            $table->decimal('tax_rate', 8, 4)->nullable()->after('code');
            $table->boolean('is_active')->default(true)->after('tax_rate');
            $table->index(['company_id', 'parent_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropForeign(['company_id']);
            $table->dropUnique(['code']);
            $table->dropIndex(['categories_company_id_parent_id_is_active_index']);
            $table->dropColumn(['company_id', 'parent_id', 'code', 'tax_rate', 'is_active']);
        });
    }
};
