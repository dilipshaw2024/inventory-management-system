<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'requested_date' => 'date', 'exchange_rate' => 'decimal:12', 'allow_backorders' => 'boolean', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'rejected_at' => 'datetime', 'promotion_ids' => 'array'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function store() { return $this->belongsTo(Store::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function lines() { return $this->hasMany(SalesOrderLine::class); }
    public function deliveries() { return $this->hasMany(Delivery::class); }
    public function promotion() { return $this->belongsTo(Promotion::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
