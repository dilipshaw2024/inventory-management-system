<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogChannel extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = [];
    protected $casts = ['settings' => 'array', 'is_active' => 'boolean'];
}
