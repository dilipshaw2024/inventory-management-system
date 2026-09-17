<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->string('external_reference', 150)->nullable();
            $table->string('carrier_status', 40);
            $table->timestamp('event_at');
            $table->string('event_location', 255)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'delivery_tracking_events_company_external_unique');
            $table->index(['company_id', 'delivery_id', 'event_at'], 'delivery_tracking_events_delivery_time_idx');
            $table->index(['company_id', 'carrier_status', 'updated_at'], 'delivery_tracking_events_sync_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_tracking_events');
    }
};
