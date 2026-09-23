<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->string('submission_provider', 80)->nullable()->after('filing_reference');
            $table->json('provider_response')->nullable()->after('submission_provider');
            $table->text('provider_error')->nullable()->after('provider_response');
        });
    }

    public function down(): void
    {
        Schema::table('tax_filings', function (Blueprint $table): void {
            $table->dropColumn(['submission_provider', 'provider_response', 'provider_error']);
        });
    }
};
