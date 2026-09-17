<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class InventorySerial extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['warranty_until' => 'date'];
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class, 'batch_id'); }
    public function movements() { return $this->hasMany(InventoryMovement::class, 'serial_id'); }
}
