<?php

namespace Awove\Operations;

use Awove\Core\Services\IdentityRelationships;

class Relations
{
    public static function options($user, string $role, bool $historical = false): array
    {
        $service = app(IdentityRelationships::class);

        return $service->query($user, $role, $historical)->when(! $historical, fn ($query) => $query->where('active', true))->orderBy('id')->get()->map(fn ($record) => $service->describe($user, $record))->all();
    }

    public static function describe($user, ?int $id, string $role): ?array
    {
        if (! $id) {
            return null;
        }
        $service = app(IdentityRelationships::class);
        $record = $service->query($user, $role, true)->find($id);

        return $record ? $service->describe($user, $record) : null;
    }

    public static function authorize($user, ?int $id, string $role, ?int $existing = null): void
    {
        if ($id === null || $id === $existing) {
            return;
        }
        abort_unless(app(IdentityRelationships::class)->query($user, $role)->where('active', true)->whereKey($id)->exists(), 403);
    }
}
