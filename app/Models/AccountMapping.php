<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class AccountMapping extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    public function company() { return $this->belongsTo(Company::class); }
    public function account() { return $this->belongsTo(ChartOfAccount::class, 'account_id'); }
}
