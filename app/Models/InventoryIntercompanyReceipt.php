<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryIntercompanyReceipt extends Model
{
    protected $guarded = [];
    protected $casts = ['date' => 'date'];

    public function transfer() { return $this->belongsTo(InventoryIntercompanyTransfer::class, 'transfer_id'); }
    public function company() { return $this->belongsTo(Company::class); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
}
