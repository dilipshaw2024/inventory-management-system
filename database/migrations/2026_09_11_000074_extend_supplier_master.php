<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('tax_number', 100)->nullable()->after('address');
            $table->unsignedInteger('payment_terms_days')->default(0)->after('tax_number');
            $table->string('bank_name', 150)->nullable()->after('payment_terms_days');
            $table->string('bank_account', 100)->nullable()->after('bank_name');
            $table->string('bank_code', 50)->nullable()->after('bank_account');
            $table->decimal('rating', 5, 2)->nullable()->after('bank_code');
            $table->boolean('is_active')->default(true)->after('rating');
            $table->index(['tax_number', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropIndex(['tax_number', 'is_active']);
            $table->dropColumn(['tax_number', 'payment_terms_days', 'bank_name', 'bank_account', 'bank_code', 'rating', 'is_active']);
        });
    }
};
