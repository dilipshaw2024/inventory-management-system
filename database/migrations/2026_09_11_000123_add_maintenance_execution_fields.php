<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('started_at');
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
            $table->decimal('actual_hours', 12, 4)->nullable()->after('completed_by');
            $table->decimal('labor_cost', 19, 6)->nullable()->after('actual_hours');
            $table->text('outcome')->nullable()->after('labor_cost');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_orders', function (Blueprint $table): void {
            $table->dropForeign(['completed_by']);
            $table->dropColumn(['started_at', 'completed_at', 'completed_by', 'actual_hours', 'labor_cost', 'outcome']);
        });
    }
};
