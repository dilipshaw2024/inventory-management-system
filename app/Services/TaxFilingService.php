<?php

namespace App\Services;

use App\Models\TaxFiling;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TaxFilingService
{
    public function createSnapshot(int $companyId, string $from, string $to, ?string $jurisdiction, array $payload, ?string $externalReference = null, string $returnType = 'indirect_tax'): TaxFiling
    {
        $externalReference = $externalReference ?: 'tax-filing:'.$companyId.':'.$from.':'.$to.':'.($jurisdiction ?: 'all');
        $existing = TaxFiling::where('company_id', $companyId)->where('external_reference', $externalReference)->first();
        if ($existing) return $existing;
        $snapshot = ['return_type' => $returnType, 'period_from' => $from, 'period_to' => $to, 'jurisdiction' => $jurisdiction, 'report' => $payload];
        $snapshotHash = $this->snapshotHash($snapshot);
        $summary = $payload['summary'] ?? $payload;
        return TaxFiling::create([
            'company_id' => $companyId,
            'filing_no' => 'TAX-FILE-'.$from.'-'.$to.($jurisdiction ? '-'.strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $jurisdiction), 0, 12)) : ''),
            'external_reference' => $externalReference,
            'period_from' => $from, 'period_to' => $to, 'jurisdiction' => $jurisdiction,
            'return_type' => $returnType, 'sales_tax' => (float) ($summary['sales_tax'] ?? 0), 'purchase_tax' => (float) ($summary['purchase_tax'] ?? 0), 'net_tax' => (float) ($summary['net_tax'] ?? 0),
            'snapshot_payload' => $snapshot, 'snapshot_hash' => $snapshotHash,
            'created_by' => auth()->id(),
        ]);
    }

    public function verifySnapshot(TaxFiling $filing): bool
    {
        return $filing->snapshot_payload !== null && hash_equals((string) $filing->snapshot_hash, $this->snapshotHash($filing->snapshot_payload));
    }

    private function snapshotHash(array $snapshot): string
    {
        $canonical = $this->canonicalize($snapshot);
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn ($item) => $this->canonicalize($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item);
        return $value;
    }

    public function submit(TaxFiling $filing, string $reference): TaxFiling
    {
        return DB::transaction(function () use ($filing, $reference): TaxFiling {
            $filing = TaxFiling::lockForUpdate()->findOrFail($filing->id);
            if ($filing->status !== 'draft') throw new RuntimeException('Only draft tax filings can be submitted.');
            $filing->update(['status' => 'submitted', 'filing_reference' => $reference, 'submitted_by' => auth()->id(), 'submitted_at' => now()]);
            return $filing->fresh();
        });
    }

    public function decide(TaxFiling $filing, string $status, ?string $reason = null): TaxFiling
    {
        return DB::transaction(function () use ($filing, $status, $reason): TaxFiling {
            $filing = TaxFiling::lockForUpdate()->findOrFail($filing->id);
            if ($filing->status !== 'submitted') throw new RuntimeException('Only submitted tax filings can receive a decision.');
            if ($status === 'rejected' && !$reason) throw new RuntimeException('A rejection reason is required.');
            $filing->update(['status' => $status, 'rejection_reason' => $status === 'rejected' ? $reason : null, 'decided_by' => auth()->id(), 'decided_at' => now()]);
            return $filing->fresh();
        });
    }
}
