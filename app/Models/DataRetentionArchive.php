<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class DataRetentionArchive extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['payload' => 'array', 'archived_at' => 'datetime'];
}
