<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class ProductUom extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['conversion_to_stock' => 'decimal:8', 'decimal_places' => 'integer', 'is_active' => 'boolean'];
    public function product() { return $this->belongsTo(Product::class); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
