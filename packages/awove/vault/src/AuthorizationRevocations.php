<?php

namespace Awove\Vault;

use App\Models\User;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Models\Member;
use Awove\Vault\Models\UnlockGrant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Contracts\Role;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/** Cancel authority, never decrypt content; safe even while the feature is disabled. */
final class AuthorizationRevocations
{
    public static function register(): void
    {
        config(['permission.events_enabled' => true]);
        Event::listen([PermissionAttachedEvent::class, PermissionDetachedEvent::class, RoleAttachedEvent::class, RoleDetachedEvent::class], self::class);
        User::updated(fn (User $user) => $user->wasChanged(['active', 'email', 'email_verified_at']) ? self::invalidate([$user->id]) : null);
        User::deleting(fn (User $user) => self::invalidate([$user->id]));
        $role = config('permission.models.role');
        $role::updated(fn (Model $model) => self::invalidate(self::roleUsers($model)));
        $role::deleting(fn () => self::invalidateAll());
        $permission = config('permission.models.permission');
        $permission::updated(fn (Model $model) => self::invalidate(self::permissionUsers($model)));
        $permission::deleting(fn () => self::invalidateAll());
    }

    public function handle(PermissionAttachedEvent|PermissionDetachedEvent|RoleAttachedEvent|RoleDetachedEvent $event): void
    {
        if ($event->model instanceof User) {
            self::invalidate([$event->model->id]);
        } elseif ($event->model instanceof Role) {
            self::invalidate(self::roleUsers($event->model));
        } elseif ($event->model instanceof Permission && ($event instanceof RoleAttachedEvent || $event instanceof RoleDetachedEvent)) {
            $roles = collect($event->rolesOrIds)->flatten()->map(fn ($role) => $role instanceof Model ? $role->getKey() : $role)->all();
            self::invalidate(User::whereHas('roles', fn ($query) => $query->whereKey($roles))->pluck('id')->all());
        }
    }

    private static function roleUsers(Model $role): array
    {
        return User::whereHas('roles', fn ($query) => $query->whereKey($role->getKey()))->pluck('id')->all();
    }

    private static function permissionUsers(Model $permission): array
    {
        return User::where(fn ($query) => $query->whereHas('permissions', fn ($permissions) => $permissions->whereKey($permission->getKey()))
            ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->whereKey($permission->getKey())))->pluck('id')->all();
    }

    private static function invalidateAll(): void
    {
        // Spatie can detach pivots in its own deleting listener before ours runs.
        if (Schema::hasTable('vault_members')) {
            self::invalidate(Member::pluck('user_id')->unique()->all());
        }
    }

    private static function invalidate(array $userIds): void
    {
        if ($userIds === [] || ! Schema::hasTable('vault_members') || ! Schema::hasTable('vault_unlocks') || ! Schema::hasTable('vault_challenges')) {
            return;
        }
        DB::transaction(function () use ($userIds) {
            foreach (Member::whereIn('user_id', $userIds)->orderBy('installation_id')->orderBy('user_id')->lockForUpdate()->get() as $member) {
                $member->update(['factor_generation' => $member->factor_generation + 1]);
                foreach ([UnlockGrant::class, Challenge::class] as $model) {
                    $model::where('installation_id', $member->installation_id)->where('user_id', $member->user_id)->delete();
                }
                if (Schema::hasTable('vault_audits')) {
                    DB::table('vault_audits')->insert(['installation_id' => $member->installation_id, 'actor_id' => null, 'target_user_id' => $member->user_id,
                        'item_id' => null, 'action' => 'authorization.invalidated', 'result' => 'success', 'created_at' => now()]);
                }
            }
        });
    }
}
