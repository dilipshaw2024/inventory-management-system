<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class CustomerRefund extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:6', 'exchange_rate' => 'decimal:12', 'base_amount' => 'decimal:6', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    public function inventoryReturn() { return $this->belongsTo(InventoryReturn::class, 'inventory_return_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
