<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Invoice extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['date' => 'date', 'due_date' => 'date', 'exchange_rate' => 'decimal:12', 'tax_exempt' => 'boolean', 'rejected_at' => 'datetime', 'promotion_ids' => 'array'];

    public function payment(){
        return $this->belongsTo(Payment::class,'id','invoice_id')->withDefault();
    }
    public function customer(){ return $this->belongsTo(Customer::class); }
    public function store(){ return $this->belongsTo(Store::class); }

    public function invoice_details(){
        return $this->hasMany(InvoiceDetail::class,'invoice_id','id');
    }
    public function paymentAllocations(){ return $this->hasMany(CustomerPaymentAllocation::class)->whereNull('voided_at'); }
    public function allPaymentAllocations(){ return $this->hasMany(CustomerPaymentAllocation::class); }
    public function promotion(){ return $this->belongsTo(Promotion::class); }
    public function maintenanceOrder(){ return $this->belongsTo(MaintenanceOrder::class); }
}
