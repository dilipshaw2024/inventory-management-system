<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SupplierContact extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
}
