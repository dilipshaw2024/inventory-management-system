<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->decimal('carrier_charge_amount', 19, 6)->nullable()->after('tracking_no');
            $table->string('carrier_charge_currency', 3)->nullable()->after('carrier_charge_amount');
            $table->decimal('carrier_charge_exchange_rate', 24, 12)->nullable()->after('carrier_charge_currency');
            $table->string('carrier_invoice_reference', 150)->nullable()->after('carrier_charge_exchange_rate');
            $table->enum('carrier_settlement_status', ['pending', 'settled'])->default('pending')->after('carrier_invoice_reference');
            $table->string('carrier_settlement_reference', 150)->nullable()->after('carrier_settlement_status');
            $table->timestamp('carrier_settled_at')->nullable()->after('carrier_settlement_reference');
            $table->foreignId('carrier_settled_by')->nullable()->after('carrier_settled_at')->constrained('users')->nullOnDelete();
            $table->foreignId('carrier_settlement_journal_id')->nullable()->after('carrier_settled_by')->constrained('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'carrier_settlement_status'], 'deliveries_carrier_settlement_idx');
            $table->index(['company_id', 'carrier_settlement_reference'], 'deliveries_carrier_settlement_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropIndex('deliveries_carrier_settlement_idx');
            $table->dropIndex('deliveries_carrier_settlement_ref_idx');
            $table->dropForeign(['carrier_settled_by']);
            $table->dropForeign(['carrier_settlement_journal_id']);
            $table->dropColumn([
                'carrier_charge_amount', 'carrier_charge_currency', 'carrier_charge_exchange_rate',
                'carrier_invoice_reference', 'carrier_settlement_status', 'carrier_settlement_reference',
                'carrier_settled_at', 'carrier_settled_by', 'carrier_settlement_journal_id',
            ]);
        });
    }
};
