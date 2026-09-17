<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class Promotion extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['discount_value' => 'decimal:6', 'buy_quantity' => 'decimal:6', 'get_quantity' => 'decimal:6', 'minimum_quantity' => 'decimal:6', 'usage_limit' => 'integer', 'usage_count' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean', 'stackable' => 'boolean'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function category() { return $this->belongsTo(Category::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
}
