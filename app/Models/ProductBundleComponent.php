<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductBundleComponent extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'sort_order' => 'integer'];
    public function bundleProduct() { return $this->belongsTo(Product::class, 'bundle_product_id'); }
    public function componentProduct() { return $this->belongsTo(Product::class, 'component_product_id'); }
}
