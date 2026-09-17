<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['minimum_quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'discount_percent' => 'decimal:4', 'is_active' => 'boolean'];
    public function priceList() { return $this->belongsTo(PriceList::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
