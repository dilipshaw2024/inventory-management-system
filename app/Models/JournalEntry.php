<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class JournalEntry extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry): void {
            $originalStatus = (string) $entry->getRawOriginal('status');
            $newStatus = (string) $entry->getAttribute('status');
            if ($originalStatus === 'reversed' || ($originalStatus === 'posted' && $newStatus !== 'reversed')) {
                throw new LogicException('Posted or reversed journals are immutable; create a reversal journal instead.');
            }
            if ($originalStatus === 'posted' && $newStatus === 'reversed') {
                $allowed = ['status', 'reversed_at', 'reversed_by', 'updated_at'];
                if (array_diff(array_keys($entry->getDirty()), $allowed)) throw new LogicException('Only the controlled journal reversal transition is allowed.');
            }
        });

        static::deleting(function (JournalEntry $entry): void {
            if (in_array((string) $entry->getRawOriginal('status'), ['posted', 'reversed'], true)) throw new LogicException('Posted or reversed journals cannot be deleted.');
        });
    }
    protected $casts = ['date' => 'date', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'consolidation_elimination' => 'boolean'];
    public function company() { return $this->belongsTo(Company::class); }
    public function lines() { return $this->hasMany(JournalLine::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function reversalOf() { return $this->belongsTo(self::class, 'reversal_of_id'); }
    public function reversal() { return $this->hasOne(self::class, 'reversal_of_id'); }
    public function reverser() { return $this->belongsTo(User::class, 'reversed_by'); }
}
