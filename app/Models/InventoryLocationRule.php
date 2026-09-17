<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class InventoryLocationRule extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function category() { return $this->belongsTo(Category::class); }
}
