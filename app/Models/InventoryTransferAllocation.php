<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransferAllocation extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'received_quantity' => 'decimal:6'];
    public function transferLine() { return $this->belongsTo(InventoryTransferLine::class, 'transfer_line_id'); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
    public function serial() { return $this->belongsTo(InventorySerial::class); }
}
