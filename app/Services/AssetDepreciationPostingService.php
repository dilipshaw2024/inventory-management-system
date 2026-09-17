<?php

namespace App\Services;

use App\Models\AccountMapping;
use App\Models\AssetDepreciationEntry;
use App\Models\ServiceAsset;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AssetDepreciationPostingService
{
    public function post(ServiceAsset $asset, string $date): AssetDepreciationEntry
    {
        $date = Carbon::parse($date)->toDateString();
        return DB::transaction(function () use ($asset, $date): AssetDepreciationEntry {
            $asset = ServiceAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($asset->status === 'retired') throw new \RuntimeException('Retired assets cannot receive depreciation postings.');
            $existing = AssetDepreciationEntry::where('asset_id', $asset->id)->whereDate('depreciation_date', $date)->first();
            if ($existing) return $existing->load('journal');

            $valuation = app(AssetValuationService::class)->snapshot($asset, Carbon::parse($date));
            $amount = round($valuation['depreciation_to_date'] - (float) ($asset->accumulated_depreciation ?? 0), 6);
            if ($amount <= 0) throw new \RuntimeException('No unposted depreciation exists for this date.');
            $companyId = $asset->company_id ?: auth()->user()?->company_id;
            $expense = $this->account('asset_depreciation_expense', $companyId);
            $reserve = $this->account('accumulated_depreciation', $companyId);
            if (!$expense || !$reserve) throw new \RuntimeException('Configure asset_depreciation_expense and accumulated_depreciation account mappings before posting.');

            $journal = app(AccountingService::class)->post([
                'company_id' => $companyId,
                'entry_no' => 'JE-DEP-'.strtoupper(bin2hex(random_bytes(5))),
                'date' => $date,
                'description' => 'Depreciation for asset '.$asset->asset_no,
            ], [
                ['account_id' => $expense, 'debit' => $amount, 'credit' => 0, 'description' => 'Asset depreciation expense'],
                ['account_id' => $reserve, 'debit' => 0, 'credit' => $amount, 'description' => 'Accumulated depreciation'],
            ], $asset);

            $entry = AssetDepreciationEntry::create(['company_id' => $companyId, 'asset_id' => $asset->id, 'journal_entry_id' => $journal->id, 'depreciation_date' => $date, 'amount' => $amount]);
            $asset->update(['accumulated_depreciation' => $valuation['depreciation_to_date'], 'last_depreciated_on' => $date]);
            return $entry->load('journal');
        });
    }

    private function account(string $key, ?int $companyId): ?int
    {
        return AccountMapping::where('mapping_key', $key)->where(fn ($query) => $query->where('company_id', $companyId)->orWhereNull('company_id'))->orderByRaw('company_id IS NULL')->value('account_id');
    }
}
