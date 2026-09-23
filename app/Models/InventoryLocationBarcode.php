<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryLocationBarcode extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    protected $casts = ['is_primary' => 'boolean'];

    public function location() { return $this->belongsTo(InventoryLocation::class); }
    public function company() { return $this->belongsTo(Company::class); }
}
