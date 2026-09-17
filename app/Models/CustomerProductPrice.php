<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class CustomerProductPrice extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['minimum_quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'discount_percent' => 'decimal:4', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
