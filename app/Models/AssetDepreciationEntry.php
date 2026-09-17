<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AssetDepreciationEntry extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['depreciation_date' => 'date', 'amount' => 'decimal:6'];
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
    public function journal() { return $this->belongsTo(JournalEntry::class, 'journal_entry_id'); }
}
