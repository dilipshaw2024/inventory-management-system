<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Supplier extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['payment_terms_days' => 'integer', 'rating' => 'decimal:2', 'tax_exempt' => 'boolean', 'is_active' => 'boolean', 'planning_calendar' => 'array'];

    public function products(){
        return $this->hasMany(Product::class, 'supplier_id');
    }

    public function purchases(){
        return $this->hasMany(Purchase::class, 'supplier_id');
    }
    public function purchasePriceList() { return $this->belongsTo(PriceList::class, 'purchase_price_list_id'); }
    public function contacts() { return $this->hasMany(SupplierContact::class); }
}
