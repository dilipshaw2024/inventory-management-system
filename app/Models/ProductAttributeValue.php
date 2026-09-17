<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class ProductAttributeValue extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    public function attribute() { return $this->belongsTo(ProductAttribute::class, 'attribute_id'); }
}
