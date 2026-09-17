<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $guarded = [];
    protected $casts = ['rate' => 'decimal:12', 'effective_date' => 'date'];
    public function fromCurrency() { return $this->belongsTo(Currency::class, 'from_currency_id'); }
    public function toCurrency() { return $this->belongsTo(Currency::class, 'to_currency_id'); }
}
