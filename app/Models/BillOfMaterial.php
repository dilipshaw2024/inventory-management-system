<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class BillOfMaterial extends Model
{
    use BelongsToCompany;
    protected $table = 'bills_of_materials';
    protected $guarded = [];
    protected $casts = ['output_quantity' => 'decimal:6', 'effective_from' => 'date', 'effective_until' => 'date', 'is_active' => 'boolean', 'approved_at' => 'datetime', 'rejected_at' => 'datetime'];
    public function product() { return $this->belongsTo(Product::class); }
    public function lines() { return $this->hasMany(BomLine::class, 'bom_id'); }
    public function byproducts() { return $this->hasMany(BomByproduct::class, 'bom_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function rejector() { return $this->belongsTo(User::class, 'rejected_by'); }
}
