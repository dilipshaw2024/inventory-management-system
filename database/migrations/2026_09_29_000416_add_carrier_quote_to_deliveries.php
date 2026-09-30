<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->foreignId('carrier_rate_card_id')->nullable()->after('carrier')->constrained('carrier_rate_cards')->nullOnDelete();
            $table->string('carrier_service_code', 80)->nullable()->after('carrier_rate_card_id');
            $table->decimal('carrier_quote_amount', 19, 6)->nullable()->after('carrier_service_code');
            $table->string('carrier_quote_currency', 3)->nullable()->after('carrier_quote_amount');
            $table->decimal('carrier_quote_weight_kg', 18, 6)->nullable()->after('carrier_quote_currency');
            $table->string('carrier_quote_origin_zone', 80)->nullable()->after('carrier_quote_weight_kg');
            $table->string('carrier_quote_destination_zone', 80)->nullable()->after('carrier_quote_origin_zone');
            $table->timestamp('carrier_quote_at')->nullable()->after('carrier_quote_destination_zone');
            $table->string('carrier_quote_external_reference', 150)->nullable()->after('carrier_quote_at');
            $table->unique(['company_id', 'carrier_quote_external_reference'], 'deliveries_company_carrier_quote_external_unique');
            $table->index(['company_id', 'carrier_rate_card_id', 'carrier_quote_at'], 'deliveries_carrier_quote_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropUnique('deliveries_company_carrier_quote_external_unique');
            $table->dropIndex('deliveries_carrier_quote_lookup_idx');
            $table->dropForeign(['carrier_rate_card_id']);
            $table->dropColumn([
                'carrier_rate_card_id', 'carrier_service_code', 'carrier_quote_amount', 'carrier_quote_currency',
                'carrier_quote_weight_kg', 'carrier_quote_origin_zone', 'carrier_quote_destination_zone',
                'carrier_quote_at', 'carrier_quote_external_reference',
            ]);
        });
    }
};
