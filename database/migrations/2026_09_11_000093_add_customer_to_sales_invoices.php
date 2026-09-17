<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('invoices', function (Blueprint $table): void { $table->foreignId('customer_id')->nullable()->after('id')->constrained('customers')->nullOnDelete(); $table->index(['customer_id', 'status']); }); } public function down(): void { Schema::table('invoices', function (Blueprint $table): void { $table->dropIndex(['customer_id', 'status']); $table->dropForeign(['customer_id']); $table->dropColumn('customer_id'); }); } };
