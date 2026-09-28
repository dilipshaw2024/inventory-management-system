<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ServiceAssetSerialHandoff extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['effective_at' => 'datetime'];

    public function asset() { return $this->belongsTo(ServiceAsset::class, 'service_asset_id'); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
