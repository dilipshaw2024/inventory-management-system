<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;
    protected $guarded = [];

    public function products(){
        return $this->hasMany(Product::class, 'supplier_id');
    }

    public function purchases(){
        return $this->hasMany(Purchase::class, 'supplier_id');
    }
}
