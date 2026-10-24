<?php

namespace Awove\Core\Services;

use Awove\Core\ContactDetails;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\IdentityActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

class IdentityHistory
{
    /** Called inside the identity service's authorized write transaction; values are never recorded. */
    public function record($actor, Contact|Company $identity, string $event, array $fields): void
    {
        if (! in_array($event, ['identity.created', 'identity.updated'], true)) {
            throw new \InvalidArgumentException('Unsupported identity history event.');
        }
        $allowed = $identity instanceof Contact ? ContactDetails::ATTRIBUTES : ['name', 'email', 'phone'];
        $fields = array_values(array_intersect($allowed, $fields));
        if ($event === 'identity.created') {
            // Normalized nulls and empty defaults were not entered as creation details.
            $fields = array_values(array_filter($fields, fn ($field) => filled($identity->getAttribute($field))));
        }
        sort($fields);
        if ($fields === []) {
            return;
        }
        IdentityActivity::create([
            'contact_id' => $identity instanceof Contact ? $identity->id : null,
            'company_id' => $identity instanceof Company ? $identity->id : null,
            'actor_id' => $actor->id,
            'event' => $event,
            'changed_fields' => $fields,
        ]);
    }

    public function forIdentity($user, Contact|Company $identity): array
    {
        Gate::forUser($user)->authorize('view', $identity);
        $key = $identity instanceof Contact ? 'contact_id' : 'company_id';

        return IdentityActivity::where($key, $identity->id)->with('actor:id,name')->orderByDesc('created_at')->orderByDesc('id')->get()->map(fn (IdentityActivity $entry) => [
            'id' => $entry->id,
            'event' => $entry->event,
            'changed_fields' => $entry->changed_fields,
            'actor' => $entry->actor?->only(['id', 'name']),
            'created_at' => CarbonImmutable::parse($entry->created_at)->toISOString(),
        ])->all();
    }
}
