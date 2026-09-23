<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EInvoiceProviderSetting extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $hidden = ['connection_config'];
    protected $casts = ['connection_config' => 'encrypted:array', 'is_active' => 'boolean'];
}
