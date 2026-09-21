<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DataRetentionPurgeRequest extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['cutoff_at' => 'datetime', 'approved_at' => 'datetime', 'consumed_at' => 'datetime'];

    public function company() { return $this->belongsTo(Company::class); }
    public function policy() { return $this->belongsTo(DataRetentionPolicy::class, 'policy_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
