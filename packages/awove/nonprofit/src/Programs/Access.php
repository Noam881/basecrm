<?php

namespace Awove\Nonprofit\Programs;

use Awove\Core\Modules;
use Awove\Nonprofit\Programs\Models\Attendance;
use Awove\Nonprofit\Programs\Models\Feedback;
use Awove\Nonprofit\Programs\Models\Group;
use Awove\Nonprofit\Programs\Models\Location;
use Awove\Nonprofit\Programs\Models\Participant;
use Awove\Nonprofit\Programs\Models\Program;
use Awove\Nonprofit\Programs\Models\Record;
use Awove\Nonprofit\Programs\Models\Session;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public const MODELS = ['programs' => Program::class, 'participants' => Participant::class, 'locations' => Location::class, 'groups' => Group::class, 'sessions' => Session::class, 'attendance' => Attendance::class, 'feedback' => Feedback::class];

    public static function fresh($u)
    {
        $u = $u?->fresh();
        abort_unless($u && $u->active && app(Modules::class)->enabled('nonprofit:programs'), 403);

        return $u;
    }

    public static function available($u, string $kind = 'programs'): bool
    {
        $u = $u?->fresh();
        $p = self::prefix($kind);

        return $u && $u->active && app(Modules::class)->enabled('nonprofit:programs') && ($u->can($p.'.view.own') || $u->can($p.'.view.all')) && ($kind !== 'attendance' || $u->can('nonprofit.programs.participants.view.own') || $u->can('nonprofit.programs.participants.view.all'));
    }

    public static function prefix(string $kind): string
    {
        return $kind === 'participants' ? 'nonprofit.programs.participants' : 'nonprofit.programs';
    }

    public static function model(string $kind): Record
    {
        abort_unless(isset(self::MODELS[$kind]), 404);
        $class = self::MODELS[$kind];

        return new $class;
    }

    /** @return Builder<Record> */
    public static function query($u, string $kind): Builder
    {
        abort_unless(isset(self::MODELS[$kind]), 404);
        $model = self::MODELS[$kind];
        $record = self::model($kind);
        $q = $record->newQuery();
        $u = $u?->fresh();
        if (! self::available($u, $kind)) {
            return $q->whereRaw('1=0');
        }
        if (in_array($kind, ['sessions', 'feedback'])) {
            return $q->whereIn('program_id', self::query($u, 'programs')->select('id'));
        }
        if ($kind === 'attendance') {
            return $q->whereIn('session_id', self::query($u, 'sessions')->select('id'))->whereIn('participant_id', self::query($u, 'participants')->select('id'));
        }

        return $u->can(self::prefix($kind).'.view.all') ? $q : $q->where('owner_id', $u->id);
    }

    public static function program($u, Record $r): Record
    {
        return self::query($u, 'programs')->findOrFail($r->getAttribute('program_id'));
    }

    public static function editable($u, string $kind, Record $r): bool
    {
        $u = $u?->fresh();
        if (! self::available($u, $kind) || $r->archived_at || ! self::query($u, $kind)->whereKey($r->id)->exists()) {
            return false;
        }
        if (in_array($kind, ['sessions', 'feedback'])) {
            return self::editable($u, 'programs', self::program($u, $r));
        }
        if ($kind === 'attendance') {
            return $u->can('nonprofit.programs.attendance.manage') && self::editable($u, 'sessions', self::query($u, 'sessions')->findOrFail($r->getAttribute('session_id')));
        }
        $p = self::prefix($kind);

        return $u->can($p.'.edit.all') || ($r->owner_id === $u->id && $u->can($p.'.edit.own'));
    }

    public static function edit($u, string $kind, Record $r): void
    {
        abort_unless(self::editable($u, $kind, $r), 403);
    }

    public static function owners($u, string $kind = 'programs', ?Record $program = null): array
    {
        $u = self::fresh($u);
        $q = config('auth.providers.users.model')::where('active', true);
        if (! $u->can(self::prefix($kind).'.assign')) {
            $q->whereKey($u->id);
        }

        return $q->get()->filter(fn ($owner) => self::available($owner, $kind) && (! $program || self::query($owner, 'programs')->whereKey($program->id)->exists()))->map(fn ($owner) => $owner->only(['id', 'name']))->values()->all();
    }
}
