<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE landed_costs MODIFY status ENUM('pending','approved','rejected','reversed') NOT NULL DEFAULT 'pending'");
        Schema::table('landed_costs', function (Blueprint $table): void {
            $table->text('reversal_reason')->nullable()->after('rejected_at');
            $table->foreignId('reversed_by')->nullable()->after('reversal_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
        });
        Schema::table('inventory_cost_layer_adjustments', function (Blueprint $table): void {
            $table->boolean('is_reversal')->default(false)->after('adjustment_amount');
            $table->foreignId('reversal_of_id')->nullable()->after('is_reversal')->constrained('inventory_cost_layer_adjustments')->restrictOnDelete();
            $table->unique(['landed_cost_id', 'cost_layer_id', 'is_reversal'], 'landed_cost_layer_adjustment_direction_unique');
            $table->dropUnique('landed_cost_layer_adjustment_unique');
        });
    }

    public function down(): void
    {
        // MySQL cannot remove an enum value while rows still contain it.
        DB::table('landed_costs')->where('status', 'reversed')->update(['status' => 'approved']);
        Schema::table('inventory_cost_layer_adjustments', function (Blueprint $table): void {
            $table->unique(['landed_cost_id', 'cost_layer_id'], 'landed_cost_layer_adjustment_unique');
            $table->dropUnique('landed_cost_layer_adjustment_direction_unique');
            $table->dropForeign(['reversal_of_id']);
            $table->dropColumn(['is_reversal', 'reversal_of_id']);
        });
        Schema::table('landed_costs', function (Blueprint $table): void {
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversal_reason', 'reversed_by', 'reversed_at']);
        });
        DB::statement("ALTER TABLE landed_costs MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    }
};
