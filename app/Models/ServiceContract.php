<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ServiceContract extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'contract_value' => 'decimal:6'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
}
