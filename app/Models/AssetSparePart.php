<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class AssetSparePart extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['quantity_per_service' => 'decimal:6', 'minimum_stock' => 'decimal:6', 'maximum_stock' => 'decimal:6'];
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
