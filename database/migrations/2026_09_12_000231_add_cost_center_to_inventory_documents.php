<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('cost_center_id')->nullable()->after('department_id')->constrained('cost_centers')->nullOnDelete();
        });
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('cost_center_id')->nullable()->after('department_id')->constrained('cost_centers')->nullOnDelete();
            $table->index(['cost_center_id', 'posted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropIndex(['cost_center_id', 'posted_at']);
            $table->dropForeign(['cost_center_id']);
            $table->dropColumn('cost_center_id');
        });
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropForeign(['cost_center_id']);
            $table->dropColumn('cost_center_id');
        });
    }
};
