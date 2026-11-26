<?php

namespace Awove\Vault;

use Awove\Core\Access as CoreAccess;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Modules;
use Awove\Vault\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class Access
{
    public static function actor($user)
    {
        abort_unless(app(Modules::class)->enabled('vault'), 404);
        $user = $user?->fresh();
        abort_unless($user && $user->active && $user->hasVerifiedEmail() && AuthMode::permits($user), 403);

        return $user;
    }

    public static function has($user, string $action): bool
    {
        $user = $user?->fresh();

        return $user && $user->active && $user->hasVerifiedEmail() && app(Modules::class)->enabled('vault') && AuthMode::permits($user) && $user->can('vault.'.$action);
    }

    public static function restricted($user): bool
    {
        return $user->can('vault.admin') || $user->can('vault.level1');
    }

    public static function items($user): Builder
    {
        $user = self::actor($user);
        $query = Item::query()->where('vault_items.installation_id', Installation::manifest()['id']);
        if (! $user->can('vault.list')) {
            return $query->whereRaw('1=0');
        }
        $query->where(fn ($query) => $query
            ->whereIn('vault_items.contact_id', CoreAccess::identities($user, Contact::class)->select('id'))
            ->orWhereIn('vault_items.company_id', CoreAccess::identities($user, Company::class)->select('id')));
        if (! self::restricted($user)) {
            $query->where('vault_items.level', 2);
        }

        return $query;
    }

    public static function parent(string $type, int $id): Contact|Company
    {
        $model = match ($type) {
            'contacts' => Contact::class,
            'companies' => Company::class,
            default => abort(422, __('Choose a customer.')),
        };

        return $model::findOrFail($id);
    }

    public static function itemParent(Item $item): Contact|Company
    {
        return $item->contact_id ? Contact::findOrFail($item->contact_id) : Company::findOrFail($item->company_id);
    }

    public static function allowed($user, string $action, ?Item $item = null, Contact|Company|null $parent = null): bool
    {
        $user = $user?->fresh();
        if (! self::has($user, $action)) {
            return false;
        }
        if ($item && ($item->installation_id !== Installation::manifest()['id'] || $item->deleted_at || ($item->level === 1 && ! self::restricted($user)))) {
            return false;
        }
        $parent ??= $item ? self::itemParent($item) : null;
        if ($parent) {
            $write = ! in_array($action, ['list', 'read', 'files.download'], true);
            if (! Gate::forUser($user)->allows($write ? 'update' : 'view', $parent)) {
                return false;
            }
        }

        return true;
    }

    public static function authorize($user, string $action, ?Item $item = null, Contact|Company|null $parent = null): void
    {
        $user = self::actor($user);
        if (! self::allowed($user, $action, $item, $parent)) {
            AuditLog::record($user, 'authorization.'.$action, $item?->id, 'denied');
            abort(403, __('You do not have permission for this Vault action.'));
        }
    }
}
