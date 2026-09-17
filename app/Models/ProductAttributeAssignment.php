<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class ProductAttributeAssignment extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    public function product() { return $this->belongsTo(Product::class); }
    public function attribute() { return $this->belongsTo(ProductAttribute::class); }
    public function value() { return $this->belongsTo(ProductAttributeValue::class, 'attribute_value_id'); }
}
