<?php

namespace Awove\Core;

use Awove\Core\Contracts\IdentityPanelContributor;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Illuminate\Support\Facades\Gate;

/** Metadata-only, installed-module contributions to an authorized customer profile. */
class IdentityPanels
{
    public function forIdentity($user, Contact|Company $identity): array
    {
        $user = $user?->fresh();
        abort_unless($user && $user->active, 403);
        Gate::forUser($user)->authorize('view', $identity);
        $panels = [];
        foreach (app(Modules::class)->installed() as $id => $module) {
            if (! app(Modules::class)->enabled($id) || ! $module instanceof IdentityPanelContributor) {
                continue;
            }
            foreach ($module->identityPanels($user, $identity) as $panel) {
                if (! preg_match('/^'.preg_quote($id, '/').'(?:[.-][a-z0-9.-]+)?$/D', $panel->id)
                    || array_key_exists($panel->id, $panels) || trim($panel->label) === '' || mb_strlen($panel->label) > 100
                    || ! preg_match('~^[A-Za-z][A-Za-z0-9_-]*(?:/[A-Za-z][A-Za-z0-9_-]*)*$~D', $panel->component)
                    || ! str_starts_with($panel->endpoint, '/') || str_starts_with($panel->endpoint, '//')
                    || strlen($panel->endpoint) > 2048 || preg_match('/[\\x00-\\x20\\\\]/', $panel->endpoint)) {
                    throw new \RuntimeException('Invalid or duplicate customer profile panel.');
                }
                $permissions = [$panel->permission, ...$panel->anyPermissions];
                foreach ($permissions as $permission) {
                    if (! is_string($permission) || ! str_starts_with($permission, $id.'.') || ! in_array($permission, $module->permissions(), true)) {
                        throw new \RuntimeException('Customer profile panel permissions must belong to their module.');
                    }
                }
                $panels[$panel->id] = null;
                if (array_filter($permissions, fn ($permission) => $user->can($permission))) {
                    $panels[$panel->id] = ['id' => $panel->id, 'label' => $panel->label, 'component' => $panel->component, 'endpoint' => $panel->endpoint];
                }
            }
        }

        return array_values(array_filter($panels));
    }
}
