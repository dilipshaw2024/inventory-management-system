<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApprovalEscalation extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['overdue_since' => 'datetime', 'acknowledged_at' => 'datetime', 'superseded_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
}
