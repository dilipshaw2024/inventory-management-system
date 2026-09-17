<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class WarrantyClaim extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['received_at' => 'date', 'covered' => 'boolean', 'resolved_at' => 'datetime'];
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
