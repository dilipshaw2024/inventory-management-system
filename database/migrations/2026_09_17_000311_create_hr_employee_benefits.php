<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_benefits', function (Blueprint $table): void {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('code', 50); $table->string('name', 150); $table->string('benefit_type', 20); $table->string('calculation', 20); $table->decimal('value', 18, 6)->default(0);
            $table->date('effective_from')->nullable(); $table->date('effective_until')->nullable(); $table->boolean('is_active')->default(true); $table->timestamps();
            $table->unique(['company_id', 'employee_id', 'code']); $table->index(['company_id', 'employee_id', 'is_active']);
        });
    }
    public function down(): void { Schema::dropIfExists('hr_employee_benefits'); }
};
