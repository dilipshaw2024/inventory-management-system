<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->enum('variance_status', ['none', 'pending', 'approved', 'rejected'])->default('none')->after('status');
            $table->text('variance_reason')->nullable()->after('variance_status');
            $table->foreignId('variance_approved_by')->nullable()->after('variance_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('variance_approved_at')->nullable()->after('variance_approved_by');
            $table->foreignId('variance_rejected_by')->nullable()->after('variance_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('variance_rejected_at')->nullable()->after('variance_rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropForeign(['variance_approved_by']);
            $table->dropForeign(['variance_rejected_by']);
            $table->dropColumn(['variance_status', 'variance_reason', 'variance_approved_by', 'variance_approved_at', 'variance_rejected_by', 'variance_rejected_at']);
        });
    }
};
