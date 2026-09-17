<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    use BelongsToCompany;

    protected $guarded = [];
    protected $casts = ['is_control_account' => 'boolean', 'is_active' => 'boolean'];
    public function company() { return $this->belongsTo(Company::class); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
    public function lines() { return $this->hasMany(JournalLine::class, 'account_id'); }
}
