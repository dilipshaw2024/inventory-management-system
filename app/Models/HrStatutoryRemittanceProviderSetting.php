<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HrStatutoryRemittanceProviderSetting extends Model
{
    protected $table = 'hr_statutory_remittance_provider_settings';

    protected $fillable = ['company_id', 'provider', 'connection_config', 'is_active'];

    protected $casts = ['connection_config' => 'encrypted:array', 'is_active' => 'boolean'];
}
