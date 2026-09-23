<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProductCompany;
use Illuminate\Database\Eloquent\Model;

class ProductCostingPolicy extends Model
{
    use BelongsToProductCompany;

    protected $guarded = [];
    protected $casts = ['standard_cost' => 'decimal:6', 'effective_from' => 'datetime'];

    public function product() { return $this->belongsTo(Product::class); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
