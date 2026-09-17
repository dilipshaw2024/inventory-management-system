<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class ErpFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['code' => 'organization.manage', 'name' => 'Manage organization', 'module' => 'organization'],
            ['code' => 'users.manage', 'name' => 'Manage users and roles', 'module' => 'security'],
            ['code' => 'inventory.view', 'name' => 'View inventory', 'module' => 'inventory'],
            ['code' => 'inventory.post', 'name' => 'Post inventory movements', 'module' => 'inventory'],
            ['code' => 'inventory.approve', 'name' => 'Approve inventory transactions', 'module' => 'inventory'],
            ['code' => 'purchasing.manage', 'name' => 'Manage purchasing', 'module' => 'purchasing'],
            ['code' => 'sales.manage', 'name' => 'Manage sales', 'module' => 'sales'],
            ['code' => 'sales.discount.override', 'name' => 'Approve sales discounts above policy', 'module' => 'sales'],
            ['code' => 'reports.view', 'name' => 'View reports', 'module' => 'reports'],
            ['code' => 'reports.export', 'name' => 'Export reports and master data', 'module' => 'reports'],
            ['code' => 'accounting.manage', 'name' => 'Manage accounting', 'module' => 'accounting'],
            ['code' => 'manufacturing.manage', 'name' => 'Manage manufacturing', 'module' => 'manufacturing'],
            ['code' => 'warehouse.manage', 'name' => 'Manage warehouse fulfillment', 'module' => 'warehouse'],
            ['code' => 'service.manage', 'name' => 'Manage service and maintenance', 'module' => 'service'],
            ['code' => 'hr.view', 'name' => 'View human resources', 'module' => 'hr'],
            ['code' => 'hr.manage', 'name' => 'Manage human resources', 'module' => 'hr'],
        ];

        $permissionModels = collect($permissions)->mapWithKeys(function (array $permission): array {
            $model = Permission::updateOrCreate(['code' => $permission['code']], $permission);
            return [$permission['code'] => $model];
        });

        $adminRole = Role::updateOrCreate(
            ['code' => 'system-admin'],
            ['name' => 'System Administrator', 'description' => 'Full ERP administration access', 'is_active' => true]
        );
        $adminRole->permissions()->sync($permissionModels->pluck('id')->all());

        if ($admin = User::where('email', 'demo.admin@example.com')->first()) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }
    }
}
