<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleConflict extends Model
{
    protected $guarded = [];
    public function role() { return $this->belongsTo(Role::class); }
    public function conflictingRole() { return $this->belongsTo(Role::class, 'conflicting_role_id'); }
}
