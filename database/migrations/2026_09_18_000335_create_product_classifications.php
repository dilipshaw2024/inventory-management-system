<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scheme', 30);
            $table->string('code', 30);
            $table->string('jurisdiction', 30)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('external_reference', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'scheme', 'code', 'jurisdiction'], 'product_classifications_scope_unique');
            $table->unique(['company_id', 'external_reference'], 'product_classifications_external_unique');
            $table->index(['company_id', 'scheme', 'jurisdiction', 'is_active'], 'pc_scheme_jurisdiction_active_idx');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('classification_id')->nullable()->after('hsn_sac_code')->constrained('product_classifications')->nullOnDelete();
            $table->index(['company_id', 'classification_id'], 'products_classification_idx');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['classification_id']);
            $table->dropIndex('products_classification_idx');
            $table->dropColumn('classification_id');
        });
        Schema::dropIfExists('product_classifications');
    }
};
