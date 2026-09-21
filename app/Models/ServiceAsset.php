<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class ServiceAsset extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = [
        'warranty_until' => 'date', 'in_service_date' => 'date', 'last_depreciated_on' => 'date', 'installation_date' => 'date',
        'acquisition_cost' => 'decimal:6', 'salvage_value' => 'decimal:6',
        'accumulated_depreciation' => 'decimal:6', 'meter_value' => 'decimal:6',
        'depreciation_units_total' => 'decimal:6', 'depreciation_units_used' => 'decimal:6',
    ];
    public function product() { return $this->belongsTo(Product::class); }
    public function serial() { return $this->belongsTo(InventorySerial::class, 'inventory_serial_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function requests() { return $this->hasMany(ServiceRequest::class, 'asset_id'); }
    public function maintenanceOrders() { return $this->hasMany(MaintenanceOrder::class, 'asset_id'); }
    public function warrantyClaims() { return $this->hasMany(WarrantyClaim::class, 'asset_id'); }
    public function spareParts() { return $this->hasMany(AssetSparePart::class, 'asset_id'); }
    public function depreciationEntries() { return $this->hasMany(AssetDepreciationEntry::class, 'asset_id'); }
    public function meterReadings() { return $this->hasMany(ServiceAssetMeterReading::class, 'asset_id')->orderByDesc('occurred_at')->orderByDesc('id'); }
    public function ownershipTransfers() { return $this->hasMany(AssetOwnershipTransfer::class, 'asset_id')->orderByDesc('effective_date')->orderByDesc('id'); }
}
