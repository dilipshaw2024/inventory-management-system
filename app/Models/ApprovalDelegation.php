<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApprovalDelegation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['document_types' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_active' => 'boolean'];
    public function delegator() { return $this->belongsTo(User::class, 'delegator_id'); }
    public function delegate() { return $this->belongsTo(User::class, 'delegate_id'); }
}
