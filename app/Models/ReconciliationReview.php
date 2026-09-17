<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ReconciliationReview extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['as_of_date' => 'date', 'rows' => 'array', 'reviewed_at' => 'datetime'];
    public function reviewer() { return $this->belongsTo(User::class, 'reviewed_by'); }
}
