<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_assets', function (Blueprint $table): void { $table->id(); $table->string('asset_no')->unique(); $table->string('name'); $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); $table->string('serial_no')->nullable(); $table->text('location')->nullable(); $table->date('warranty_until')->nullable(); $table->enum('status', ['active', 'under_service', 'retired'])->default('active'); $table->timestamps(); });
        Schema::create('service_requests', function (Blueprint $table): void { $table->id(); $table->string('request_no')->unique(); $table->foreignId('asset_id')->nullable()->constrained('service_assets')->nullOnDelete(); $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete(); $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal'); $table->text('description'); $table->enum('status', ['open', 'assigned', 'in_progress', 'resolved', 'cancelled'])->default('open'); $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps(); });
        Schema::create('maintenance_orders', function (Blueprint $table): void { $table->id(); $table->string('order_no')->unique(); $table->foreignId('asset_id')->constrained('service_assets')->restrictOnDelete(); $table->foreignId('service_request_id')->nullable()->constrained('service_requests')->nullOnDelete(); $table->enum('maintenance_type', ['preventive', 'corrective', 'inspection'])->default('corrective'); $table->date('scheduled_date')->nullable(); $table->text('notes')->nullable(); $table->enum('status', ['planned', 'in_progress', 'completed', 'cancelled'])->default('planned'); $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamps(); });
        Schema::create('maintenance_parts', function (Blueprint $table): void { $table->id(); $table->foreignId('maintenance_order_id')->constrained()->cascadeOnDelete(); $table->foreignId('product_id')->constrained()->restrictOnDelete(); $table->decimal('quantity', 18, 6); $table->decimal('unit_cost', 19, 6)->default(0); $table->timestamps(); });
    }
    public function down(): void { Schema::dropIfExists('maintenance_parts'); Schema::dropIfExists('maintenance_orders'); Schema::dropIfExists('service_requests'); Schema::dropIfExists('service_assets'); }
};
