<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductionReceipt extends Model
{
    use BelongsToCompany;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'material_cost' => 'decimal:6',
        'operation_cost' => 'decimal:6',
        'byproduct_cost' => 'decimal:6',
        'net_cost' => 'decimal:6',
        'serial_numbers' => 'array',
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
        'best_before_date' => 'date',
        'warranty_until' => 'date',
    ];

    public function productionOrder() { return $this->belongsTo(ProductionOrder::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function batch() { return $this->belongsTo(InventoryBatch::class); }
}
