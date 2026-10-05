<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ServiceContract extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'contract_value' => 'decimal:6', 'request_limit' => 'integer', 'requests_used' => 'integer'];
    protected $appends = ['remaining_requests'];
    public function customer() { return $this->belongsTo(Customer::class); }
    public function asset() { return $this->belongsTo(ServiceAsset::class); }
    public function getRemainingRequestsAttribute(): ?int { return $this->request_limit === null ? null : max(0, (int) $this->request_limit - (int) $this->requests_used); }
}
