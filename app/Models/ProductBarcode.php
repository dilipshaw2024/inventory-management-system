<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class ProductBarcode extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['is_primary' => 'boolean'];
    public function product() { return $this->belongsTo(Product::class); }
}
