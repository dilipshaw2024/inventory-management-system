<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class MaintenancePart extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'returned_quantity' => 'decimal:6', 'unit_cost' => 'decimal:6'];
    public function maintenanceOrder() { return $this->belongsTo(MaintenanceOrder::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
    public function returns() { return $this->hasMany(MaintenancePartReturn::class); }
}
