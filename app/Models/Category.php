<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Category extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['tax_rate' => 'decimal:4', 'is_active' => 'boolean', 'required_attribute_ids' => 'array'];

    public function products(){
        return $this->hasMany(Product::class, 'category_id');
    }

    public function purchases(){
        return $this->hasMany(Purchase::class, 'category_id');
    }

    public function invoiceDetails(){
        return $this->hasMany(InvoiceDetail::class, 'category_id');
    }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
}
