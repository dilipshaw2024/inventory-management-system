<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StockCountSchedule extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['next_due' => 'date', 'last_generated_at' => 'datetime', 'is_active' => 'boolean'];
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
