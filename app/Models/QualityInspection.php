<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class QualityInspection extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:8', 'sample_quantity' => 'decimal:8', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'disposition_applied_at' => 'datetime'];

    public function plan() { return $this->belongsTo(QualityInspectionPlan::class, 'plan_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
    public function serial() { return $this->belongsTo(InventorySerial::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function inspector() { return $this->belongsTo(User::class, 'inspected_by'); }
    public function results() { return $this->hasMany(QualityInspectionResult::class, 'inspection_id'); }
    public function inventoryStatusTransfer() { return $this->belongsTo(InventoryStatusTransfer::class, 'inventory_status_transfer_id'); }
}
