<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApprovalOverride extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['approved_at' => 'datetime', 'consumed_at' => 'datetime'];

    public function company() { return $this->belongsTo(Company::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function consumer() { return $this->belongsTo(User::class, 'consumed_by'); }
}
