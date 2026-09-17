<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Purchase extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['buying_qty' => 'decimal:6', 'unit_price' => 'decimal:6', 'buying_price' => 'decimal:6'];
 
      public function product(){
        return $this->belongsTo(Product::class,'product_id','id')->withDefault();
    }
 

     public function supplier(){
        return $this->belongsTo(Supplier::class,'supplier_id','id')->withDefault();
    }
 
     public function unit(){
        return $this->belongsTo(Unit::class,'unit_id','id')->withDefault();
    }

     public function category(){
        return $this->belongsTo(Category::class,'category_id','id')->withDefault();
    }



}
 
