<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class InventoryTransfer extends Model
{
    use BelongsToCompany;
    protected $guarded = [];

    protected $casts = ['date' => 'date', 'expected_arrival' => 'date', 'approved_at' => 'datetime', 'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'variance_resolved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function lines() { return $this->hasMany(InventoryTransferLine::class, 'transfer_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function dispatcher() { return $this->belongsTo(User::class, 'dispatched_by'); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function varianceResolver() { return $this->belongsTo(User::class, 'variance_resolved_by'); }
}
