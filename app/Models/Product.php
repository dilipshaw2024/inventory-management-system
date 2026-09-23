<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Product extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:6', 'weight_kg' => 'decimal:6', 'length_m' => 'decimal:6', 'width_m' => 'decimal:6', 'height_m' => 'decimal:6', 'buying_price' => 'decimal:6', 'selling_price' => 'decimal:6', 'tax_rate' => 'decimal:4', 'can_purchase' => 'boolean', 'can_sell' => 'boolean', 'is_stock_item' => 'boolean'];

    public function supplier(){
        return $this->belongsTo(Supplier::class,'supplier_id','id')->withDefault();
    }
 
     public function unit(){
        return $this->belongsTo(Unit::class,'unit_id','id')->withDefault();
    }

    public function category(){
        return $this->belongsTo(Category::class,'category_id','id')->withDefault();
    }

    public function brand(){
        return $this->belongsTo(Brand::class)->withDefault();
    }

    public function taxRate(){
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    public function classification(){
        return $this->belongsTo(ProductClassification::class, 'classification_id');
    }

    public function purchases(){
        return $this->hasMany(Purchase::class, 'product_id');
    }

    public function invoiceDetails(){
        return $this->hasMany(InvoiceDetail::class, 'product_id');
    }

    public function uoms(){
        return $this->hasMany(ProductUom::class);
    }

    public function parentProduct(){ return $this->belongsTo(self::class, 'parent_product_id'); }
    public function variants(){ return $this->hasMany(self::class, 'parent_product_id'); }
    public function attributeAssignments(){ return $this->hasMany(ProductAttributeAssignment::class); }
    public function costHistories(){ return $this->hasMany(ProductCostHistory::class); }
    public function costingPolicies(){ return $this->hasMany(ProductCostingPolicy::class); }
    public function replenishmentPolicies(){ return $this->hasMany(InventoryReplenishmentPolicy::class); }
    public function barcodes(){ return $this->hasMany(ProductBarcode::class); }
    public function bundleComponents(){ return $this->hasMany(ProductBundleComponent::class, 'bundle_product_id'); }
    public function bundledIn(){ return $this->hasMany(ProductBundleComponent::class, 'component_product_id'); }
    public function attachments(){ return $this->morphMany(DocumentAttachment::class, 'attachable'); }




}
