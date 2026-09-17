<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->boolean('recount_required')->default(false)->after('status');
            $table->foreignId('recount_requested_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('recount_requested_at')->nullable()->after('recount_requested_by');
            $table->text('recount_reason')->nullable()->after('recount_requested_at');
        });
        Schema::table('stock_count_lines', function (Blueprint $table): void {
            $table->decimal('recounted_quantity', 18, 6)->nullable()->after('counted_quantity');
            $table->foreignId('recounted_by')->nullable()->after('recounted_quantity')->constrained('users')->nullOnDelete();
            $table->timestamp('recounted_at')->nullable()->after('recounted_by');
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_lines', function (Blueprint $table): void {
            $table->dropForeign(['recounted_by']);
            $table->dropColumn(['recounted_quantity', 'recounted_by', 'recounted_at']);
        });
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->dropForeign(['recount_requested_by']);
            $table->dropColumn(['recount_required', 'recount_requested_by', 'recount_requested_at', 'recount_reason']);
        });
    }
};
