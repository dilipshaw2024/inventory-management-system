<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_count_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('schedule_no', 80);
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->unsignedInteger('frequency_days');
            $table->date('next_due');
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'schedule_no'], 'stock_count_schedules_company_no_unique');
            $table->index(['company_id', 'next_due', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_schedules');
    }
};
