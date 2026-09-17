<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->decimal('credit_limit', 19, 6)->default(0)->after('address');
            $table->unsignedInteger('credit_days')->default(0)->after('credit_limit');
            $table->boolean('credit_hold')->default(false)->after('credit_days');
        });
    }
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void { $table->dropColumn(['credit_limit', 'credit_days', 'credit_hold']); });
    }
};
