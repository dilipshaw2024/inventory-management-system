<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToProductCompany;

class InvoiceDetail extends Model
{
    use HasFactory, BelongsToProductCompany;
    protected $guarded = [];
    protected $casts = ['selling_qty' => 'decimal:6', 'unit_price' => 'decimal:6', 'selling_price' => 'decimal:6', 'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:6'];
    public function batch() { return $this->belongsTo(InventoryBatch::class); }

      public function product(){
        return $this->belongsTo(Product::class,'product_id','id')->withDefault();
    }
  

    public function category(){
        return $this->belongsTo(Category::class,'category_id','id')->withDefault();
    }

    public function invoice(){
        return $this->belongsTo(Invoice::class,'invoice_id','id')->withDefault();
    }
}
 
