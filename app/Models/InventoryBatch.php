<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryBatch extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['manufacturing_date' => 'date', 'expiry_date' => 'date', 'best_before_date' => 'date', 'warranty_until' => 'date', 'recalled_at' => 'datetime', 'recall_released_at' => 'datetime'];
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function serials() { return $this->hasMany(InventorySerial::class, 'batch_id'); }
    public function movements() { return $this->hasMany(InventoryMovement::class, 'batch_id'); }
    public function recalledBy() { return $this->belongsTo(User::class, 'recalled_by'); }
    public function recallReleasedBy() { return $this->belongsTo(User::class, 'recall_released_by'); }
}
