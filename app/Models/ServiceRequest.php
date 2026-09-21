<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class ServiceRequest extends Model
{
    use BelongsToCompany;
    protected $appends = ['sla_status'];
    protected $guarded = [];
    protected $casts = ['assigned_at' => 'datetime', 'response_due_at' => 'datetime', 'sla_breached_at' => 'datetime', 'last_sla_escalated_at' => 'datetime'];
    public function asset() { return $this->belongsTo(ServiceAsset::class, 'asset_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function assignee() { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function contract() { return $this->belongsTo(ServiceContract::class, 'contract_id'); }

    public function getSlaStatusAttribute(): string
    {
        return app(\App\Services\ServiceSlaService::class)->status($this->response_due_at, $this->assigned_at, (string) $this->status);
    }
}
