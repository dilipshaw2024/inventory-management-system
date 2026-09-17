<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $guarded = [];
    protected $casts = ['is_base' => 'boolean', 'is_active' => 'boolean'];
    public function outgoingRates() { return $this->hasMany(ExchangeRate::class, 'from_currency_id'); }
    public function incomingRates() { return $this->hasMany(ExchangeRate::class, 'to_currency_id'); }
}
