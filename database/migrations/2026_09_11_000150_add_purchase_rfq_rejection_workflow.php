<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE purchase_rfqs MODIFY status ENUM('draft','submitted','closed','cancelled','rejected') NOT NULL DEFAULT 'draft'");
        Schema::table('purchase_rfqs', function (Blueprint $table): void {
            $table->text('rejection_reason')->nullable()->after('description');
            $table->foreignId('rejected_by')->nullable()->after('rejection_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_rfqs', function (Blueprint $table): void {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejection_reason', 'rejected_by', 'rejected_at']);
        });
        DB::statement("ALTER TABLE purchase_rfqs MODIFY status ENUM('draft','submitted','closed','cancelled') NOT NULL DEFAULT 'draft'");
    }
};
