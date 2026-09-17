<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class BomLine extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'scrap_percent' => 'decimal:4'];
    public function bom() { return $this->belongsTo(BillOfMaterial::class, 'bom_id'); }
    public function component() { return $this->belongsTo(Product::class, 'component_product_id'); }
}
