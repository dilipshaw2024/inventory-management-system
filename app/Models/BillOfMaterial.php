<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class BillOfMaterial extends Model
{
    use BelongsToCompany;
    protected $table = 'bills_of_materials';
    protected $guarded = [];
    protected $casts = ['output_quantity' => 'decimal:6', 'effective_from' => 'date', 'effective_until' => 'date', 'is_active' => 'boolean'];
    public function product() { return $this->belongsTo(Product::class); }
    public function lines() { return $this->hasMany(BomLine::class, 'bom_id'); }
    public function byproducts() { return $this->hasMany(BomByproduct::class, 'bom_id'); }
}
