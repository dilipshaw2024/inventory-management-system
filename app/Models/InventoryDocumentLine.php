<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryDocumentLine extends Model
{
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'batch_allocations' => 'array'];

    public function document() { return $this->belongsTo(InventoryDocument::class, 'inventory_document_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function costCenter() { return $this->belongsTo(CostCenter::class); }
}
