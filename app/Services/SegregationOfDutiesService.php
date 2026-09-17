<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RoleConflict;
use App\Models\User;

class SegregationOfDutiesService
{
    public function assertCanAssign(User $user, Role $role): void
    {
        $roleIds = $user->roles()->pluck('roles.id');
        $conflict = RoleConflict::where('role_id', $role->id)->whereIn('conflicting_role_id', $roleIds)->with('conflictingRole')->first()
            ?? RoleConflict::where('conflicting_role_id', $role->id)->whereIn('role_id', $roleIds)->with('role')->first();
        if ($conflict) throw new \RuntimeException('Segregation-of-duties conflict: '.$role->name.' cannot be assigned with '.($conflict->conflictingRole?->name ?? $conflict->role?->name).'.');
    }
}
