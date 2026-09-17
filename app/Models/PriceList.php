<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    public function items() { return $this->hasMany(PriceListItem::class); }
}
