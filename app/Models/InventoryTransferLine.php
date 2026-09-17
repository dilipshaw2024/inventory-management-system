<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransferLine extends Model
{
    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:6', 'received_quantity' => 'decimal:6', 'unit_cost' => 'decimal:6'];

    public function transfer() { return $this->belongsTo(InventoryTransfer::class, 'transfer_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function sourceLocation() { return $this->belongsTo(InventoryLocation::class, 'source_location_id'); }
    public function destinationLocation() { return $this->belongsTo(InventoryLocation::class, 'destination_location_id'); }
    public function transferSerials() { return $this->hasMany(InventoryTransferSerial::class, 'transfer_line_id'); }
    public function allocations() { return $this->hasMany(InventoryTransferAllocation::class, 'transfer_line_id'); }
}
