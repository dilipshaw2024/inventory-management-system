<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranchCompany;

class InventoryLocation extends Model
{
    use SoftDeletes, BelongsToBranchCompany;

    protected $guarded = [];
    protected $casts = ['capacity' => 'decimal:6', 'capacity_weight_kg' => 'decimal:6', 'capacity_volume_m3' => 'decimal:6', 'is_active' => 'boolean'];

    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function movements() { return $this->hasMany(InventoryMovement::class, 'location_id'); }
    public function rules() { return $this->hasMany(InventoryLocationRule::class, 'location_id'); }
    public function barcodes() { return $this->hasMany(InventoryLocationBarcode::class, 'location_id'); }
}
