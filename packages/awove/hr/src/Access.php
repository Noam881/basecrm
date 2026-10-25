<?php

namespace Awove\Hr;

use Awove\Core\Modules;
use Awove\Hr\Models\Candidate;
use Awove\Hr\Models\EmploymentCase;
use Awove\Hr\Models\EmploymentProfile;
use Awove\Hr\Models\Vacancy;
use Illuminate\Database\Eloquent\Model;

class Access
{
    public const MODELS = ['employees' => EmploymentProfile::class, 'vacancies' => Vacancy::class, 'candidates' => Candidate::class, 'cases' => EmploymentCase::class];

    public static function prefix(string $section): string
    {
        return 'hr.'.match ($section) {
            'employees' => 'employment','vacancies','candidates' => 'recruitment','cases' => 'cases',default => abort(404)
        };
    }

    public static function available($u, string $section): bool
    {
        $u = $u?->fresh();
        $p = self::prefix($section);

        return $u && $u->active && app(Modules::class)->enabled('hr') && ($u->can($p.'.view.own') || $u->can($p.'.view.all'));
    }

    public static function query($u, string $section)
    {
        $u = $u?->fresh();
        $model = self::MODELS[$section] ?? abort(404);
        $q = $model::query();
        $p = self::prefix($section);
        if (! self::available($u, $section)) {
            return $q->whereRaw('1=0');
        }

        return $u->can($p.'.view.all') ? $q : $q->where('owner_id', $u->id);
    }

    public static function section(Model $record): string
    {
        return array_search($record::class, self::MODELS, true) ?: abort(404);
    }

    public static function view($u, Model $record): void
    {
        abort_unless(self::query($u, self::section($record))->whereKey($record->getKey())->exists(), 403);
    }

    public static function editable($u, Model $record): bool
    {
        $u = $u?->fresh();
        $s = self::section($record);
        $p = self::prefix($s);

        return self::available($u, $s) && ! $record->getAttribute('archived_at') && (! $record instanceof EmploymentCase || ! $record->getAttribute('completed_on')) && ($u->can($p.'.view.all') || $record->getAttribute('owner_id') === $u->id) && ($u->can($p.'.edit.all') || ($record->getAttribute('owner_id') === $u->id && $u->can($p.'.edit.own')));
    }

    public static function edit($u, Model $record): void
    {
        self::view($u, $record);
        abort_unless(self::editable($u, $record), 403);
    }

    public static function fresh($u)
    {
        $u = $u?->fresh();
        abort_unless($u && $u->active, 403);

        return $u;
    }

    public static function owners($u, string $section): array
    {
        $u = $u?->fresh();
        if (! self::available($u, $section)) {
            return [];
        } $q = config('auth.providers.users.model')::where('active', true);
        if (! $u->can(self::prefix($section).'.assign')) {
            $q->whereKey($u->id);
        }

        return $q->get()->filter(fn ($owner) => self::available($owner, $section))->map(fn ($owner) => $owner->only(['id', 'name']))->values()->all();
    }
}
