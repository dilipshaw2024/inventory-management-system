<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class DataRetentionPolicy extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['archive_enabled' => 'boolean', 'purge_enabled' => 'boolean', 'is_active' => 'boolean'];
}
