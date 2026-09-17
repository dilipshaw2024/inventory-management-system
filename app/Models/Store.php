<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranchCompany;

class Store extends Model
{
    use SoftDeletes, BelongsToBranchCompany;

    protected $guarded = [];
    protected $casts = ['allow_negative_stock' => 'boolean', 'pos_settings' => 'array'];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
}
