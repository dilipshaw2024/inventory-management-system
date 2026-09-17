<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ApprovalAction extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['approved_at' => 'datetime'];

    public function actor() { return $this->belongsTo(User::class, 'acted_by'); }
}
