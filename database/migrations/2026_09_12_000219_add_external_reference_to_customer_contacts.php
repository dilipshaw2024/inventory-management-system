<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table): void {
            $table->string('external_reference', 150)->nullable()->after('id');
            $table->unique(['customer_id', 'external_reference'], 'customer_contacts_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table): void {
            $table->dropUnique('customer_contacts_external_unique');
            $table->dropColumn('external_reference');
        });
    }
};
