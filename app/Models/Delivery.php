<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['date' => 'date', 'approved_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime', 'rejected_at' => 'datetime', 'sla_breached_at' => 'datetime', 'last_sla_escalated_at' => 'datetime', 'carrier_settled_at' => 'datetime', 'carrier_charge_amount' => 'decimal:6', 'carrier_charge_exchange_rate' => 'decimal:12'];
    public function salesOrder() { return $this->belongsTo(SalesOrder::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function lines() { return $this->hasMany(DeliveryLine::class); }
    public function operations() { return $this->hasMany(DeliveryOperation::class); }
    public function trackingEvents() { return $this->hasMany(DeliveryTrackingEvent::class); }
    public function packages() { return $this->hasMany(DeliveryPackage::class); }
    public function pickWaves() { return $this->belongsToMany(PickWave::class, 'pick_wave_deliveries'); }
    public function carrierSettlementJournal() { return $this->belongsTo(JournalEntry::class, 'carrier_settlement_journal_id'); }
    public function carrierSettledBy() { return $this->belongsTo(User::class, 'carrier_settled_by'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
