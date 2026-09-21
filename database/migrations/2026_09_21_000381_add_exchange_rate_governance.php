<?php

use IlluminateDatabaseMigrationsMigration;
use IlluminateDatabaseSchemaBlueprint;
use IlluminateSupportFacadesSchema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->string('source_type', 20)->default('manual')->after('effective_date')->index();
            $table->string('source_reference', 150)->nullable()->after('source_type');
            $table->timestamp('retrieved_at')->nullable()->after('source_reference');
            $table->boolean('is_active')->default(true)->after('retrieved_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropIndex(['source_type']);
            $table->dropIndex(['is_active']);
            $table->dropColumn(['source_type', 'source_reference', 'retrieved_at', 'is_active']);
        });
    }
};
