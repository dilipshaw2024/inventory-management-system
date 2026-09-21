<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\BelongsToCompany;

class RoleConflict extends Model
{
    use BelongsToCompany;
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
    public function company() { return $this->belongsTo(Company::class); }
    public function role() { return $this->belongsTo(Role::class); }
    public function conflictingRole() { return $this->belongsTo(Role::class, 'conflicting_role_id'); }
}
