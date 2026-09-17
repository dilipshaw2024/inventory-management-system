<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!DB::selectOne('SHOW INDEX FROM supplier_contacts WHERE Key_name = ?', ['supplier_contacts_supplier_external_unique'])) {
            Schema::table('supplier_contacts', function (Blueprint $table): void {
                $table->unique(['supplier_id', 'external_reference'], 'supplier_contacts_supplier_external_unique');
            });
        }
        if (!DB::selectOne('SHOW INDEX FROM supplier_contacts WHERE Key_name = ?', ['supplier_contacts_company_id_index'])) {
            Schema::table('supplier_contacts', function (Blueprint $table): void {
                // The supplier-scoped unique index cannot support the
                // company_id foreign key after the old composite is removed.
                $table->index('company_id', 'supplier_contacts_company_id_index');
            });
        }
        if (DB::selectOne('SHOW INDEX FROM supplier_contacts WHERE Key_name = ?', ['supplier_contacts_company_external_unique'])) {
            Schema::table('supplier_contacts', function (Blueprint $table): void {
                $table->dropUnique('supplier_contacts_company_external_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('supplier_contacts', function (Blueprint $table): void {
            $table->dropUnique('supplier_contacts_supplier_external_unique');
            $table->unique(['company_id', 'external_reference'], 'supplier_contacts_company_external_unique');
        });
    }
};
