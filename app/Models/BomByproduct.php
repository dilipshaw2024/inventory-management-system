<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class BomByproduct extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'cost_share_percent' => 'decimal:4'];
    public function bom() { return $this->belongsTo(BillOfMaterial::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
