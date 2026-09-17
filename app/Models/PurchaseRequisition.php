<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PurchaseRequisition extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['requested_date' => 'date', 'required_date' => 'date', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    public function lines() { return $this->hasMany(PurchaseRequisitionLine::class); }
    public function supplier() { return $this->belongsTo(Supplier::class, 'suggested_supplier_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
