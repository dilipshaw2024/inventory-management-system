<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_manifests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('manifest_no', 80)->unique();
            $table->string('external_reference', 150)->nullable();
            $table->string('carrier', 255)->nullable();
            $table->string('tracking_reference', 255)->nullable();
            $table->date('manifest_date');
            $table->enum('status', ['planned', 'handed_off', 'closed', 'cancelled'])->default('planned');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('handed_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'external_reference'], 'dispatch_manifests_company_external_unique');
            $table->index(['company_id', 'status', 'manifest_date'], 'dispatch_manifests_status_date_idx');
        });

        Schema::create('dispatch_manifest_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispatch_manifest_id')->constrained('dispatch_manifests')->cascadeOnDelete();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['dispatch_manifest_id', 'delivery_id'], 'dispatch_manifest_delivery_unique');
            $table->index('delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_manifest_deliveries');
        Schema::dropIfExists('dispatch_manifests');
    }
};
