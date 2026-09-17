<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToCompany;

class Department extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $guarded = [];

    public function company() { return $this->belongsTo(Company::class); }
}
