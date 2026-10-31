<?php

namespace Awove\Assets;

use Awove\Assets\Models\Asset;
use Awove\Assets\Models\Assignment;
use Awove\Assets\Models\MaintenanceRecord;
use Awove\Core\Modules;
use Illuminate\Database\Eloquent\Builder;

class Access
{
    public static function fresh($u)
    {
        $u = $u?->fresh();
        abort_unless($u && $u->active, 403);

        return $u;
    }

    public static function available($u): bool
    {
        $u = $u?->fresh();

        return $u && $u->active && app(Modules::class)->enabled('assets') && ($u->can('assets.view.own') || $u->can('assets.view.all'));
    }

    /** @return Builder<Asset> */
    public static function query($u): Builder
    {
        $u = $u?->fresh();
        $q = Asset::query();
        if (! self::available($u)) {
            return $q->whereRaw('1=0');
        }

        return $u->can('assets.view.all') ? $q : $q->where('owner_id', $u->id);
    }

    /** @return Builder<Assignment> */
    public static function assignments($u): Builder
    {
        return Assignment::whereIn('asset_id', self::query($u)->select('id'));
    }

    /** @return Builder<MaintenanceRecord> */
    public static function maintenance($u): Builder
    {
        return MaintenanceRecord::whereIn('asset_id', self::query($u)->select('id'));
    }

    public static function editable($u, Asset $asset): bool
    {
        $u = $u?->fresh();

        return self::available($u) && ! $asset->archived_at && self::query($u)->whereKey($asset->id)->exists() && ($u->can('assets.edit.all') || ($asset->owner_id === $u->id && $u->can('assets.edit.own')));
    }

    public static function edit($u, Asset $asset): void
    {
        abort_unless(self::editable($u, $asset), 403);
    }

    public static function owners($u, ?Asset $asset = null, bool $maintenance = false): array
    {
        $u = self::fresh($u);
        if (! self::available($u)) {
            return [];
        }
        $q = config('auth.providers.users.model')::where('active', true);
        if (! $u->can('assets.assign')) {
            $q->whereKey($u->id);
        }

        return $q->orderBy('name')->get()->filter(fn ($p) => self::available($p) && (! $maintenance || ! $asset || self::query($p)->whereKey($asset->id)->exists()))->map(fn ($p) => $p->only(['id', 'name']))->values()->all();
    }
}
