<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->after('location_id')->constrained('departments')->nullOnDelete();
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('department_id')->nullable()->after('location_id')->constrained('departments')->nullOnDelete();
            $table->index(['department_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex(['department_id', 'posted_at']);
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
