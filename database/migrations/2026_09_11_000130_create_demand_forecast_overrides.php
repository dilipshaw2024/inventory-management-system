<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecast_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('forecast_quantity', 18, 6);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'product_id', 'period_start', 'period_end'], 'demand_forecast_override_unique');
            $table->index(['company_id', 'period_start', 'period_end'], 'forecast_override_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_forecast_overrides');
    }
};
