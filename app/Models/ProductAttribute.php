<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class ProductAttribute extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
    public function values() { return $this->hasMany(ProductAttributeValue::class, 'attribute_id'); }
}
