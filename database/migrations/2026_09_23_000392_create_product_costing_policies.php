<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_costing_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('costing_method', 30);
            $table->decimal('standard_cost', 19, 6)->nullable();
            $table->timestamp('effective_from');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'effective_from'], 'product_costing_policy_effective_unique');
            $table->index(['product_id', 'effective_from'], 'product_costing_policy_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costing_policies');
    }
};
