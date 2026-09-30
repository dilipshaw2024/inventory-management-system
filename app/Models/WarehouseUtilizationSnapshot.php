<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseUtilizationSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'as_of_date' => 'date',
        'occupied_quantity' => 'float',
        'capacity' => 'float',
        'utilization_percent' => 'float',
        'occupied_weight_kg' => 'float',
        'capacity_weight_kg' => 'float',
        'weight_utilization_percent' => 'float',
        'occupied_volume_m3' => 'float',
        'capacity_volume_m3' => 'float',
        'volume_utilization_percent' => 'float',
        'descendant_count' => 'integer',
    ];

    public function company() { return $this->belongsTo(Company::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class, 'location_id'); }
}
