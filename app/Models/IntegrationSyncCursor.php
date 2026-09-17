<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class IntegrationSyncCursor extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['cursor_updated_at' => 'datetime', 'acknowledged_at' => 'datetime'];
}
