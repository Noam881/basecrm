<?php

namespace Awove\Nonprofit\Fundraising;

use Awove\Core\Modules;
use Awove\Core\Services\IdentityRelationships;
use Awove\Nonprofit\Fundraising\Models\Constituent;
use Awove\Nonprofit\Fundraising\Models\Pledge;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public const PREFIX = 'nonprofit.fundraising.';

    public const ROLES = ['donor', 'partner', 'prospect'];

    public static function fresh($u)
    {
        $u = $u?->fresh();
        abort_unless($u && $u->active, 403);

        return $u;
    }

    public static function available($u): bool
    {
        $u = $u?->fresh();

        return $u && $u->active && app(Modules::class)->enabled('nonprofit:fundraising') && ($u->can(self::PREFIX.'view.own') || $u->can(self::PREFIX.'view.all'));
    }

    public static function relationships($u)
    {
        return app(IdentityRelationships::class)->query($u, includeArchived: true)->whereIn('role', self::ROLES);
    }

    /** @return Builder<Constituent> */
    public static function query($u): Builder
    {
        $u = $u?->fresh();
        $q = Constituent::whereIn('relationship_id', self::relationships($u)->select('id'));
        if (! self::available($u)) {
            return $q->whereRaw('1=0');
        }

        return $u->can(self::PREFIX.'view.all') ? $q : $q->where('owner_id', $u->id);
    }

    /** @return Builder<Pledge> */
    public static function pledges($u): Builder
    {
        $u = $u?->fresh();
        $q = Pledge::whereIn('constituent_id', self::query($u)->select('id'));

        return self::available($u) && $u->can(self::PREFIX.'pledges.view') ? $q : $q->whereRaw('1=0');
    }

    public static function editable($u, Constituent $r): bool
    {
        $u = $u?->fresh();

        return self::available($u) && $r->archived_at === null && self::query($u)->whereKey($r->id)->exists() && ($u->can(self::PREFIX.'edit.all') || ($r->owner_id === $u->id && $u->can(self::PREFIX.'edit.own')));
    }

    public static function edit($u, Constituent $r): void
    {
        abort_unless(self::editable($u, $r), 403);
    }

    public static function money($u, bool $write = false): void
    {
        $u = self::fresh($u);
        abort_unless(self::available($u) && $u->can(self::PREFIX.'pledges.view') && (! $write || $u->can(self::PREFIX.'pledges.manage')), 403);
    }

    public static function owners($u, ?int $relationshipId = null): array
    {
        $u = self::fresh($u);
        if (! self::available($u)) {
            return [];
        }

        return config('auth.providers.users.model')::where('active', true)->when(! $u->can(self::PREFIX.'assign'), fn ($q) => $q->whereKey($u->id))->orderBy('name')->get()->filter(fn ($p) => self::available($p) && ($relationshipId === null || self::relationships($p)->whereKey($relationshipId)->exists()))->map(fn ($p) => $p->only(['id', 'name']))->values()->all();
    }
}
