<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class Routing extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
    public function bom() { return $this->belongsTo(BillOfMaterial::class); }
    public function operations() { return $this->hasMany(RoutingOperation::class)->orderBy('sequence'); }
}
