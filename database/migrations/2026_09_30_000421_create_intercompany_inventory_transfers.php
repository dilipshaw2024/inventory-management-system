<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_intercompany_transfers', function (Blueprint $table): void {
            $table->id();
            $table->string('transfer_no')->unique();
            $table->unsignedBigInteger('source_company_id');
            $table->foreign('source_company_id', 'ic_transfer_source_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->unsignedBigInteger('destination_company_id');
            $table->foreign('destination_company_id', 'ic_transfer_dest_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->string('external_reference')->nullable();
            $table->date('date');
            $table->date('expected_arrival')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by', 'ic_transfer_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by', 'ic_transfer_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('dispatched_by')->nullable();
            $table->foreign('dispatched_by', 'ic_transfer_dispatched_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->foreign('received_by', 'ic_receipt_received_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->text('receiving_note')->nullable();
            $table->timestamps();
            $table->index(['source_company_id', 'status'], 'ic_transfer_source_status_idx');
            $table->index(['destination_company_id', 'status'], 'ic_transfer_dest_status_idx');
            $table->index(['source_company_id', 'external_reference'], 'ic_transfer_source_ref_idx');
        });

        Schema::create('inventory_intercompany_transfer_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->foreign('transfer_id', 'ic_line_transfer_fk')->references('id')->on('inventory_intercompany_transfers')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreign('product_id', 'ic_line_product_fk')->references('id')->on('products')->restrictOnDelete();
            $table->unsignedBigInteger('source_location_id');
            $table->foreign('source_location_id', 'ic_line_source_location_fk')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->unsignedBigInteger('destination_location_id');
            $table->foreign('destination_location_id', 'ic_line_dest_location_fk')->references('id')->on('inventory_locations')->restrictOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 19, 6)->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_intercompany_transfer_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transfer_line_id');
            $table->foreign('transfer_line_id', 'ic_alloc_line_fk')->references('id')->on('inventory_intercompany_transfer_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->foreign('batch_id', 'ic_alloc_batch_fk')->references('id')->on('inventory_batches')->nullOnDelete();
            $table->unsignedBigInteger('serial_id')->nullable();
            $table->foreign('serial_id', 'ic_alloc_serial_fk')->references('id')->on('inventory_serials')->nullOnDelete();
            $table->decimal('quantity', 18, 6);
            $table->decimal('received_quantity', 18, 6)->default(0);
            $table->decimal('unit_cost', 19, 6)->nullable();
            $table->timestamps();
            $table->index(['transfer_line_id', 'batch_id'], 'ic_alloc_line_batch_idx');
            $table->index(['transfer_line_id', 'serial_id'], 'ic_alloc_line_serial_idx');
        });

        Schema::create('inventory_intercompany_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->foreign('transfer_id', 'ic_receipt_transfer_fk')->references('id')->on('inventory_intercompany_transfers')->restrictOnDelete();
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id', 'ic_receipt_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->string('receipt_no')->unique();
            $table->string('external_reference')->nullable();
            $table->date('date');
            $table->unsignedBigInteger('received_by')->nullable();
            $table->foreign('received_by', 'ic_transfer_received_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'transfer_id'], 'ic_receipt_company_transfer_idx');
            $table->index(['company_id', 'external_reference'], 'ic_receipt_company_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_intercompany_receipts');
        Schema::dropIfExists('inventory_intercompany_transfer_allocations');
        Schema::dropIfExists('inventory_intercompany_transfer_lines');
        Schema::dropIfExists('inventory_intercompany_transfers');
    }
};
