<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductClassification extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function products() { return $this->hasMany(Product::class, 'classification_id'); }
}
