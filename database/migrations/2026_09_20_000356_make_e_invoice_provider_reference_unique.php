<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('e_invoice_submissions')
            ->select('provider', 'external_reference', DB::raw('COUNT(*) as duplicate_count'))
            ->whereNotNull('external_reference')
            ->groupBy('provider', 'external_reference')
            ->having('duplicate_count', '>', 1)
            ->first();

        if ($duplicates) {
            throw new RuntimeException(sprintf(
                'Cannot enforce e-invoice provider-reference uniqueness; duplicate provider/reference exists for %s/%s.',
                $duplicates->provider,
                $duplicates->external_reference,
            ));
        }

        Schema::table('e_invoice_submissions', function (Blueprint $table): void {
            $table->dropIndex('e_invoice_submissions_provider_external_reference_index');
            $table->unique(['provider', 'external_reference'], 'einvoice_provider_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('e_invoice_submissions', function (Blueprint $table): void {
            $table->dropUnique('einvoice_provider_external_unique');
            $table->index(['provider', 'external_reference']);
        });
    }
};
