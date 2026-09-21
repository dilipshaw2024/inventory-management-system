<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseRfqSupplier extends Model
{
    protected $guarded = [];
    protected $casts = ['quoted_at' => 'datetime', 'portal_token_expires_at' => 'datetime', 'portal_last_accessed_at' => 'datetime'];
    public function rfq() { return $this->belongsTo(PurchaseRfq::class, 'purchase_rfq_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function quotations() { return $this->hasMany(PurchaseSupplierQuotation::class); }
}
