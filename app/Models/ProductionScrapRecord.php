<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductionScrapRecord extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'recovery_quantity' => 'decimal:6', 'recovery_unit_cost' => 'decimal:6', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];

    public function productionOrder() { return $this->belongsTo(ProductionOrder::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function recoveryProduct() { return $this->belongsTo(Product::class, 'recovery_product_id'); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejector() { return $this->belongsTo(User::class, 'rejected_by'); }
}
