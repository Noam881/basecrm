<?php

namespace Awove\Core\Services;

use Awove\Core\Events\SignInLinkRequested;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Users
{
    public function deactivate($actor, int $id, array $data): void
    {
        Gate::forUser($actor)->authorize('users.manage');
        $service = app(Leads::class);

        DB::transaction(function () use ($id, $data, $service, $actor) {
            DB::select('SELECT pg_advisory_xact_lock(175542201)');
            $model = config('auth.providers.users.model');
            $u = $model::lockForUpdate()->findOrFail($id);
            if (! $data['active'] && $u->hasRole('Administrator')) {
                abort_if($model::role('Administrator')->where('active', true)->where('id', '!=', $u->id)->count() === 0, 422, __('The last active administrator cannot be deactivated.'));
            }$target = null;
            if (! empty($data['reassign_to'])) {
                $target = $model::where('active', true)->findOrFail($data['reassign_to']);
                abort_if($target->id === $u->id, 422);
            }
            if (! $data['active']) {
                foreach (Lead::where('owner_id', $u->id)->whereNull('archived_at')->get() as $lead) {
                    $service->update($actor, $lead->id, ['owner_id' => $target?->id, 'version' => $lead->version]);
                }foreach (Task::where('assignee_id', $u->id)->where('status', 'open')->lockForUpdate()->get() as $t) {
                    $t->update(['status' => 'cancelled', 'version' => $t->version + 1]);
                    $service->log($t->lead()->firstOrFail(), $actor, 'task.cancelled', ['task_id' => $t->id, 'reason' => 'user deactivated']);
                }DB::table('sessions')->where('user_id', $u->id)->delete();
                $u->remember_token = null;
            }$u->active = $data['active'];
            $u->save();
        });

    }

    public function invite($actor, array $data): void
    {
        Gate::forUser($actor)->authorize('users.manage');

        $u = DB::transaction(function () use ($data) {
            $model = config('auth.providers.users.model');
            $u = $model::forceCreate(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => Str::random(64), 'email_verified_at' => now()]);
            $u->assignRole($data['role']);

            return $u;
        });
        event(new SignInLinkRequested($u->id));

    }
}
