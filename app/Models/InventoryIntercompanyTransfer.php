<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryIntercompanyTransfer extends Model
{
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'expected_arrival' => 'date', 'approved_at' => 'datetime', 'dispatched_at' => 'datetime', 'received_at' => 'datetime'];

    public function sourceCompany() { return $this->belongsTo(Company::class, 'source_company_id'); }
    public function destinationCompany() { return $this->belongsTo(Company::class, 'destination_company_id'); }
    public function lines() { return $this->hasMany(InventoryIntercompanyTransferLine::class, 'transfer_id'); }
    public function receipts() { return $this->hasMany(InventoryIntercompanyReceipt::class, 'transfer_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function dispatcher() { return $this->belongsTo(User::class, 'dispatched_by'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
}
