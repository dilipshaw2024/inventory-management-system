<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ServiceAssetMeterReading extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['meter_value' => 'decimal:6', 'occurred_at' => 'datetime', 'metadata' => 'array'];

    public function asset() { return $this->belongsTo(ServiceAsset::class, 'asset_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
