<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranchCompany;

class Warehouse extends Model
{
    use SoftDeletes, BelongsToBranchCompany;

    protected $guarded = [];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function locations() { return $this->hasMany(InventoryLocation::class); }
}
