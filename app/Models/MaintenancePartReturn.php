<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MaintenancePartReturn extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6'];

    public function maintenanceOrder() { return $this->belongsTo(MaintenanceOrder::class); }
    public function maintenancePart() { return $this->belongsTo(MaintenancePart::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
