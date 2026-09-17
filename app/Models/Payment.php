<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Payment extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['paid_amount' => 'decimal:6', 'due_amount' => 'decimal:6', 'total_amount' => 'decimal:6', 'discount_amount' => 'decimal:6', 'exchange_rate' => 'decimal:12', 'base_amount' => 'decimal:6', 'is_reversed' => 'boolean', 'reversed_at' => 'datetime'];

     public function customer(){
        return $this->belongsTo(Customer::class,'customer_id','id')->withDefault();
    } 

     public function invoice(){
        return $this->belongsTo(Invoice::class,'invoice_id','id')->withDefault();
    }

     public function allocations(){ return $this->hasMany(CustomerPaymentAllocation::class)->whereNull('voided_at'); }
     public function allAllocations(){ return $this->hasMany(CustomerPaymentAllocation::class); }
} 
 
