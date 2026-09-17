<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'approved_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'rejected_at' => 'datetime'];
    public function salesOrder() { return $this->belongsTo(SalesOrder::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function lines() { return $this->hasMany(DeliveryLine::class); }
    public function operations() { return $this->hasMany(DeliveryOperation::class); }
    public function trackingEvents() { return $this->hasMany(DeliveryTrackingEvent::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
