<?php

namespace Awove\Core\Console;

use Awove\Core\Installation;
use Awove\Core\Models\Status;
use Awove\Core\Modules;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class Install extends Command
{
    protected $signature = 'crm:install';

    protected $description = 'Add missing defaults without overwriting installation configuration';

    public function handle(): int
    {
        $permissions = ['leads.view.own', 'leads.view.all', 'leads.edit.own', 'leads.edit.all', 'leads.create', 'leads.assign', 'users.manage', 'settings.manage', 'identities.manage', 'records.archive'];
        foreach (app(Modules::class)->installed() as $module) {
            $permissions = array_merge($permissions, $module->permissions());
        }
        $newPermissions = [];
        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($permission->wasRecentlyCreated) {
                $newPermissions[] = $name;
            }
        }
        $admin = Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']);
        $employee = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            if (($admin->wasRecentlyCreated || in_array($p, $newPermissions)) && ! $admin->hasPermissionTo($p)) {
                $admin->givePermissionTo($p);
            }
        }
        foreach (['leads.view.own', 'leads.edit.own', 'leads.create'] as $p) {
            if ($employee->wasRecentlyCreated && ! $employee->hasPermissionTo($p)) {
                $employee->givePermissionTo($p);
            }
        }
        $labels = Installation::manifest()['statuses'] ?? [];
        foreach (['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'proposal' => 'Proposal sent', 'won' => 'Won', 'lost' => 'Lost'] as $id => $name) {
            Status::firstOrCreate(['id' => $id], ['name' => $labels[$id] ?? $name, 'position' => array_search($id, ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost']), 'kind' => in_array($id, ['won', 'lost']) ? $id : 'active']);
        }
        $this->info('Missing defaults installed. Existing configuration retained.');

        return self::SUCCESS;
    }
}
