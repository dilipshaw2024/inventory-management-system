<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class StockCount extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['count_date' => 'date', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'recount_required' => 'boolean', 'recount_requested_at' => 'datetime'];
    public function lines() { return $this->hasMany(StockCountLine::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function recountRequester() { return $this->belongsTo(User::class, 'recount_requested_by'); }
}
