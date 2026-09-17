<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'released_quantity' => 'decimal:6'];
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function salesOrderLine() { return $this->belongsTo(SalesOrderLine::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function source() { return $this->morphTo(); }
    public function getOpenQuantityAttribute(): float { return max(0, (float) $this->quantity - (float) $this->released_quantity); }
}
