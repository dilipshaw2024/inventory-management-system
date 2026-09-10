<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function products(){
        return $this->hasMany(Product::class, 'category_id');
    }

    public function purchases(){
        return $this->hasMany(Purchase::class, 'category_id');
    }

    public function invoiceDetails(){
        return $this->hasMany(InvoiceDetail::class, 'category_id');
    }
}
