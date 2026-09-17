<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->unsignedInteger('package_count')->nullable()->after('tracking_no');
            $table->decimal('total_weight', 18, 6)->nullable()->after('package_count');
            $table->string('weight_unit', 12)->nullable()->after('total_weight');
            $table->decimal('length', 18, 6)->nullable()->after('weight_unit');
            $table->decimal('width', 18, 6)->nullable()->after('length');
            $table->decimal('height', 18, 6)->nullable()->after('width');
            $table->string('proof_of_delivery')->nullable()->after('height');
            $table->timestamp('delivered_at')->nullable()->after('proof_of_delivery');
        });
    }
    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void { $table->dropColumn(['package_count', 'total_weight', 'weight_unit', 'length', 'width', 'height', 'proof_of_delivery', 'delivered_at']); });
    }
};
