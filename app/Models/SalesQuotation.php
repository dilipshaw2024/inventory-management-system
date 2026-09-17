<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SalesQuotation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['quote_date' => 'date', 'valid_until' => 'date', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function lines() { return $this->hasMany(SalesQuotationLine::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
