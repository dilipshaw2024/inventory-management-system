<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryTransferSerial extends Model
{
    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime'];
    public function transferLine() { return $this->belongsTo(InventoryTransferLine::class, 'transfer_line_id'); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'serial_id'); }
}
