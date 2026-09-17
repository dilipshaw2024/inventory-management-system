<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AssetOwnershipTransfer extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['effective_date' => 'date'];
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
    public function previousCustomer() { return $this->belongsTo(Customer::class, 'previous_customer_id'); }
    public function newCustomer() { return $this->belongsTo(Customer::class, 'new_customer_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
