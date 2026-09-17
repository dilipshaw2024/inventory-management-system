<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Model;

class InventoryStatusBalance extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6'];
    public function product() { return $this->belongsTo(Product::class); }
    public function location() { return $this->belongsTo(InventoryLocation::class); }
}
