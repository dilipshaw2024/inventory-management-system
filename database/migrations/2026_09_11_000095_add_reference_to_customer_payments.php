<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('payments', function (Blueprint $table): void { $table->string('reference')->nullable()->after('customer_id'); $table->index(['customer_id', 'reference']); }); } public function down(): void { Schema::table('payments', function (Blueprint $table): void { $table->dropIndex(['customer_id', 'reference']); $table->dropColumn('reference'); }); } };
