<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Modules;
use Awove\Nonprofit\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class Access
{
    public static function available($user): bool
    {
        $u = $user?->fresh();

        return $u && $u->active && app(Modules::class)->enabled('nonprofit:projects') && ($u->can('nonprofit.projects.view.own') || $u->can('nonprofit.projects.view.all'));
    }

    public static function actor($user)
    {
        $u = $user?->fresh();
        abort_unless(self::available($u), 403);

        return $u;
    }

    /** @return Builder<Project> */
    public static function query($user): Builder
    {
        $u = $user?->fresh();
        $q = Project::query();
        if (! self::available($u)) {
            return $q->whereRaw('1=0');
        }

        return $u->can('nonprofit.projects.view.all') ? $q : $q->where('nonprofit_projects.owner_id', $u->id);
    }

    public static function find($user, int $id): Project
    {
        return self::query($user)->findOrFail($id);
    }

    public static function visible($user, Project $p): bool
    {
        return self::query($user)->whereKey($p->id)->exists();
    }

    public static function editable($user, Project $p, bool $activeOnly = false): bool
    {
        $u = $user?->fresh();

        return self::visible($u, $p) && ! $p->archived_at && (! $activeOnly || ! in_array($p->status, ['completed', 'cancelled'], true)) && ($u->can('nonprofit.projects.edit.all') || ($p->owner_id === $u->id && $u->can('nonprofit.projects.edit.own')));
    }

    public static function edit($user, Project $p, bool $activeOnly = false): void
    {
        abort_unless(self::editable($user, $p, $activeOnly), 403);
    }

    public static function owner($user, ?int $owner, ?Project $p = null): void
    {
        if ($p && $p->owner_id === $owner) {
            return;
        }
        abort_unless($owner === $user->id || $user->can('nonprofit.projects.assign'), 403);
        if ($owner !== null) {
            $candidate = config('auth.providers.users.model')::find($owner);
            if (! self::available($candidate)) {
                throw ValidationException::withMessages(['owner_id' => __('Choose an active staff member with Projects access.')]);
            }
        }
    }

    public static function owners($user, ?Project $p = null): array
    {
        $u = self::actor($user);
        $q = config('auth.providers.users.model')::where('active', true);
        if (! $u->can('nonprofit.projects.assign')) {
            $q->whereKey($u->id);
        }

        return $q->get()->filter(fn ($owner) => self::available($owner) && (! $p || self::visible($owner, $p)))->map(fn ($owner) => $owner->only(['id', 'name']))->values()->all();
    }

    public static function version($record, array $input, string $key = 'version'): void
    {
        $data = validator($input, [$key => 'required|integer|min:1'])->validate();
        abort_unless((int) $data[$key] === $record->version, 409, __('This record changed. Reload before saving.'));
    }
}
