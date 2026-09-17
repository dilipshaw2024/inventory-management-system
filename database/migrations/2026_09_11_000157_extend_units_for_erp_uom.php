<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('code', 40)->nullable()->after('name');
            $table->unsignedTinyInteger('decimal_places')->default(3)->after('code');
            $table->string('dimension', 30)->default('unit')->after('decimal_places');
            $table->boolean('is_base')->default(false)->after('dimension');
            $table->index(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex(['company_id', 'code']);
            $table->dropColumn(['company_id', 'code', 'decimal_places', 'dimension', 'is_base']);
        });
    }
};
