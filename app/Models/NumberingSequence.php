<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class NumberingSequence extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['next_number' => 'integer', 'padding' => 'integer'];
    public function company() { return $this->belongsTo(Company::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
