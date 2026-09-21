<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['planned_quantity' => 'decimal:6', 'completed_quantity' => 'decimal:6', 'material_cost' => 'decimal:6', 'operation_cost' => 'decimal:6', 'byproduct_cost' => 'decimal:6', 'production_cost' => 'decimal:6', 'bom_snapshot' => 'array', 'planned_date' => 'date', 'output_manufacturing_date' => 'date', 'output_expiry_date' => 'date', 'output_best_before_date' => 'date', 'output_warranty_until' => 'date', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime', 'paused_at' => 'datetime', 'closed_at' => 'datetime'];
    public function bom() { return $this->belongsTo(BillOfMaterial::class, 'bom_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function pausedBy() { return $this->belongsTo(User::class, 'paused_by'); }
    public function closedBy() { return $this->belongsTo(User::class, 'closed_by'); }
    public function operations() { return $this->hasMany(ProductionOperation::class, 'production_order_id')->orderBy('sequence'); }
}
