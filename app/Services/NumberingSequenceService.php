<?php

namespace App\Services;

use App\Models\NumberingSequence;
use Illuminate\Support\Facades\DB;

class NumberingSequenceService
{
    public function nextOrFallback(string $documentType, string $fallback, ?int $companyId = null, ?int $branchId = null): string
    {
        if (!NumberingSequence::where('document_type', $documentType)->where('company_id', $companyId)->where('branch_id', $branchId)->exists()) return $fallback;
        return $this->next($documentType, $companyId, $branchId);
    }

    public function next(string $documentType, ?int $companyId = null, ?int $branchId = null): string
    {
        return DB::transaction(function () use ($documentType, $companyId, $branchId): string {
            $sequence = NumberingSequence::where('document_type', $documentType)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()->firstOrFail();
            $number = (string) $sequence->prefix.str_pad((string) $sequence->next_number, $sequence->padding, '0', STR_PAD_LEFT);
            $sequence->increment('next_number');
            return $number;
        });
    }

    public function previewOrFallback(string $documentType, string $fallback, ?int $companyId = null, ?int $branchId = null): string
    {
        $sequence = NumberingSequence::where('document_type', $documentType)->where('company_id', $companyId)->where('branch_id', $branchId)->first();
        if (!$sequence) return $fallback;
        return (string) $sequence->prefix.str_pad((string) $sequence->next_number, $sequence->padding, '0', STR_PAD_LEFT);
    }
}
