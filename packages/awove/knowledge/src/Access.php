<?php

namespace Awove\Knowledge;

use Awove\Core\Modules;
use Awove\Knowledge\Models\Entry;
use Awove\Knowledge\Models\Renewal;
use Awove\Knowledge\Models\SystemReference;
use Illuminate\Database\Eloquent\Model;

class Access
{
    public const MODELS = ['library' => Entry::class, 'renewals' => Renewal::class, 'systems' => SystemReference::class];

    public static function prefix(string $s): string
    {
        abort_unless(isset(self::MODELS[$s]), 404);

        return 'knowledge.'.$s;
    }

    public static function fresh($u)
    {
        $u = $u?->fresh();
        abort_unless($u && $u->active, 403);

        return $u;
    }

    public static function available($u, string $s): bool
    {
        $u = $u?->fresh();
        $p = self::prefix($s);

        return $u && $u->active && app(Modules::class)->enabled('knowledge') && ($u->can($p.'.view.own') || $u->can($p.'.view.all'));
    }

    public static function query($u, string $s)
    {
        $u = $u?->fresh();
        $m = self::MODELS[$s] ?? abort(404);
        $q = $m::query();
        if (! self::available($u, $s)) {
            return $q->whereRaw('1=0');
        }

        return $u->can(self::prefix($s).'.view.all') ? $q : $q->where('owner_id', $u->id);
    }

    public static function section(Model $r): string
    {
        return array_search($r::class, self::MODELS, true) ?: abort(404);
    }

    public static function editable($u, Model $r): bool
    {
        $u = $u?->fresh();
        $s = self::section($r);
        $p = self::prefix($s);

        return self::available($u, $s) && ! $r->getAttribute('archived_at') && ($u->can($p.'.view.all') || $r->getAttribute('owner_id') === $u->id) && ($u->can($p.'.edit.all') || ($r->getAttribute('owner_id') === $u->id && $u->can($p.'.edit.own')));
    }

    public static function edit($u, Model $r): void
    {
        abort_unless(self::query($u, self::section($r))->whereKey($r->getKey())->exists() && self::editable($u, $r), 403);
    }

    public static function owners($u, string $s): array
    {
        $u = $u?->fresh();
        if (! self::available($u, $s)) {
            return [];
        }$q = config('auth.providers.users.model')::where('active', true);
        if (! $u->can(self::prefix($s).'.assign')) {
            $q->whereKey($u->id);
        }

        return $q->get()->filter(fn ($v) => self::available($v, $s))->map(fn ($v) => $v->only(['id', 'name']))->values()->all();
    }
}
