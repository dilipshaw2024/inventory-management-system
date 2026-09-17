<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandedCostAllocation extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:6', 'per_unit_amount' => 'decimal:6'];
    public function landedCost() { return $this->belongsTo(LandedCost::class); }
    public function goodsReceiptLine() { return $this->belongsTo(GoodsReceiptLine::class); }
}
