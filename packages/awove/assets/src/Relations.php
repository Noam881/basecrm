<?php

namespace Awove\Assets;

use Awove\Core\Services\IdentityRelationships;

class Relations
{
    public static function options($u, string $role): array
    {
        $service = app(IdentityRelationships::class);

        return $service->query($u, $role)->where('active', true)->orderBy('id')->get()->map(fn ($r) => self::summary($u, $r->id, $role))->all();
    }

    public static function summary($u, ?int $id, string $role): ?array
    {
        if (! $id) {
            return null;
        }
        $service = app(IdentityRelationships::class);
        $r = $service->query($u, $role, true)->find($id);
        if (! $r) {
            return null;
        }
        $d = $service->describe($u, $r);

        return ['id' => $r->id, 'name' => $d['name'], 'type' => $d['type'], 'active' => $d['active'] && $d['archived_at'] === null];
    }

    public static function select($u, int $id, string $role): void
    {
        abort_unless(app(IdentityRelationships::class)->query($u, $role)->where('active', true)->whereKey($id)->lockForUpdate()->exists(), 403);
    }
}
