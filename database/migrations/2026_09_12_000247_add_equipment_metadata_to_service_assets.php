<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->string('manufacturer', 150)->nullable()->after('name');
            $table->string('model_no', 150)->nullable()->after('manufacturer');
            $table->date('installation_date')->nullable()->after('model_no');
            $table->string('condition', 30)->default('operational')->after('installation_date');
            $table->decimal('meter_value', 19, 6)->nullable()->after('condition');
            $table->index(['company_id', 'condition']);
        });
    }

    public function down(): void
    {
        Schema::table('service_assets', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'condition']);
            $table->dropColumn(['manufacturer', 'model_no', 'installation_date', 'condition', 'meter_value']);
        });
    }
};
