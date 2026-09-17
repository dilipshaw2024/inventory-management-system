<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('product_cost_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('old_costing_method', 30)->nullable();
            $table->string('new_costing_method', 30);
            $table->decimal('old_standard_cost', 19, 6)->nullable();
            $table->decimal('new_standard_cost', 19, 6)->nullable();
            $table->timestamp('effective_at');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'effective_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('product_cost_histories'); }
};
