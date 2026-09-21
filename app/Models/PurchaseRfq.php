<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PurchaseRfq extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['issue_date' => 'date', 'response_due' => 'date', 'rejected_at' => 'datetime'];
    public function lines() { return $this->hasMany(PurchaseRfqLine::class); }
    public function requisition() { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function suppliers() { return $this->hasMany(PurchaseRfqSupplier::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
