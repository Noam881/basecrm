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
        $corePermissions = ['leads.view.own', 'leads.view.all', 'leads.edit.own', 'leads.edit.all', 'leads.create', 'leads.assign', 'users.manage', 'settings.manage', 'identities.manage', 'records.archive', 'imports.manage', 'exports.view'];
        $permissions = [...$corePermissions, 'identities.view.all', 'identities.classifications.view', 'identities.classifications.manage', 'identities.classifications.assign'];
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
        foreach ($corePermissions as $p) {
            if (($admin->wasRecentlyCreated || in_array($p, $newPermissions)) && ! $admin->hasPermissionTo($p)) {
                $admin->givePermissionTo($p);
            }
        }
        foreach (['leads.view.own', 'leads.edit.own', 'leads.create'] as $p) {
            if ($employee->wasRecentlyCreated && ! $employee->hasPermissionTo($p)) {
                $employee->givePermissionTo($p);
            }
        }
        $manifest = Installation::manifest();
        $labels = $manifest['statuses'] ?? [];
        $defaults = ['new' => 'New', 'discussion' => 'In discussion', 'proposal' => 'Proposal sent', 'won' => 'Won', 'lost' => 'Lost'];
        // Fictional legacy fixtures keep their extra stages; existing client rows are never removed.
        if ($manifest['legacy_sales_stages'] ?? false) {
            $defaults += ['contacted' => 'Contacted', 'qualified' => 'Qualified'];
        }
        foreach ($defaults as $id => $name) {
            Status::firstOrCreate(['id' => $id], ['name' => $labels[$id] ?? $name, 'position' => array_search($id, array_keys($defaults)), 'kind' => in_array($id, ['won', 'lost']) ? $id : 'active']);
        }
        $this->info('Missing defaults installed. Existing configuration retained.');

        return self::SUCCESS;
    }
}
