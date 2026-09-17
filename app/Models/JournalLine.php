<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

class JournalLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('journal_company', function (Builder $builder): void {
            $companyId = auth()->user()?->company_id;
            if ($companyId) {
                $builder->whereHas('entry', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->where('company_id', $companyId)->orWhereNull('company_id')));
            }
        });
        static::creating(function (JournalLine $line): void {
            if ($line->entryIsImmutable()) throw new LogicException('Posted or reversed journal lines cannot be added.');
        });
        static::updating(function (JournalLine $line): void {
            if ($line->entryIsImmutable()) throw new LogicException('Posted or reversed journal lines are immutable.');
        });
        static::deleting(function (JournalLine $line): void {
            if ($line->entryIsImmutable()) throw new LogicException('Posted or reversed journal lines cannot be deleted.');
        });
    }
    protected $casts = ['debit' => 'decimal:6', 'credit' => 'decimal:6', 'exchange_rate' => 'decimal:12'];
    public function entry() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
    public function account() { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
    public function department() { return $this->belongsTo(Department::class); }
    public function costCenter() { return $this->belongsTo(CostCenter::class); }

    private function entryIsImmutable(): bool
    {
        return $this->journal_entry_id !== null && JournalEntry::whereKey($this->journal_entry_id)->whereIn('status', ['posted', 'reversed'])->exists();
    }
}
