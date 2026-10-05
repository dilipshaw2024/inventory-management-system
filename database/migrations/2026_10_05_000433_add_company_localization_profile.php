<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('country_code', 2)->nullable()->after('address');
            $table->string('locale', 35)->nullable()->after('country_code');
            $table->string('timezone', 64)->nullable()->after('locale');
            $table->string('date_format', 32)->nullable()->after('timezone');
            $table->string('decimal_separator', 1)->nullable()->after('date_format');
            $table->string('thousands_separator', 1)->nullable()->after('decimal_separator');
            $table->string('tax_registration_scheme', 40)->nullable()->after('tax_number');
            $table->string('tax_registration_number', 100)->nullable()->after('tax_registration_scheme');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn([
                'country_code',
                'locale',
                'timezone',
                'date_format',
                'decimal_separator',
                'thousands_separator',
                'tax_registration_scheme',
                'tax_registration_number',
            ]);
        });
    }
};
