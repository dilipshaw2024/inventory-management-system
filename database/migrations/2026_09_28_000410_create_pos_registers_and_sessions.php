<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_registers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('external_reference', 150)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'pos_registers_company_code_unique');
            $table->unique(['company_id', 'external_reference'], 'pos_registers_company_external_unique');
            $table->index(['company_id', 'store_id', 'is_active'], 'pos_registers_company_store_active_idx');
        });

        Schema::create('pos_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('register_id')->constrained('pos_registers')->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->decimal('opening_cash', 19, 6)->default(0);
            $table->decimal('expected_cash', 19, 6)->nullable();
            $table->decimal('closing_cash', 19, 6)->nullable();
            $table->decimal('variance', 19, 6)->nullable();
            $table->text('closing_note')->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'external_reference'], 'pos_sessions_company_external_unique');
            $table->index(['company_id', 'register_id', 'status'], 'pos_sessions_company_register_status_idx');
            $table->index(['company_id', 'opened_at'], 'pos_sessions_company_opened_idx');
        });

        Schema::create('pos_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('pos_sessions')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 19, 6);
            $table->string('currency_code', 3)->default('USD');
            $table->string('reference', 150)->nullable();
            $table->text('note')->nullable();
            $table->dateTime('occurred_at');
            $table->string('external_reference', 150)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'external_reference'], 'pos_cash_session_external_unique');
            $table->index(['company_id', 'session_id', 'type'], 'pos_cash_company_session_type_idx');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('pos_session_id')->nullable()->after('invoice_id')->constrained('pos_sessions')->nullOnDelete();
            $table->index(['company_id', 'pos_session_id'], 'payments_company_pos_session_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['pos_session_id']);
            $table->dropIndex('payments_company_pos_session_idx');
            $table->dropColumn('pos_session_id');
        });
        Schema::dropIfExists('pos_cash_movements');
        Schema::dropIfExists('pos_sessions');
        Schema::dropIfExists('pos_registers');
    }
};
