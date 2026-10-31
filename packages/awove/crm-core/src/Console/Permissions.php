<?php

namespace Awove\Core\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/** Trusted deployment/operator command; module enablement itself never grants access. */
class Permissions extends Command
{
    protected $signature = 'crm:permissions {email} {--grant=*} {--revoke=*}';

    protected $description = 'Explicitly grant or revoke installed permissions on an existing active account';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->where('active', true)->first();
        if (! $user) {
            $this->error('An active account is required.');

            return self::FAILURE;
        }
        $grants = $this->option('grant');
        $revokes = $this->option('revoke');
        foreach (array_merge($grants, $revokes) as $name) {
            if (! Permission::where('name', $name)->where('guard_name', 'web')->exists()) {
                $this->error('Unknown permission. Run crm:install and use exact installed permission names.');

                return self::FAILURE;
            }
        }
        foreach ($revokes as $name) {
            if ($user->getPermissionsViaRoles()->contains('name', $name)) {
                $this->error('A role grants this permission. Change the role through controlled administration first.');

                return self::FAILURE;
            }
        }
        DB::transaction(function () use ($user, $grants, $revokes) {
            foreach ($grants as $name) {
                $user->givePermissionTo($name);
            }
            foreach ($revokes as $name) {
                $user->revokePermissionTo($name);
            }
        });
        $this->line($user->fresh()->getAllPermissions()->pluck('name')->sort()->values()->toJson());

        return self::SUCCESS;
    }
}
