<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->foreignId('production_order_id')->nullable()->after('location_id')->constrained('production_orders')->nullOnDelete();
            $table->index(['company_id', 'production_order_id', 'document_type', 'status'], 'inventory_documents_production_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_documents', function (Blueprint $table): void {
            $table->dropIndex('inventory_documents_production_idx');
            $table->dropConstrainedForeignId('production_order_id');
        });
    }
};
