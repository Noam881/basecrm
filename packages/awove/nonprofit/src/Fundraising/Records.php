<?php

namespace Awove\Nonprofit\Fundraising;

use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Money;
use Awove\Core\Services\IdentityRelationships;
use Awove\Nonprofit\Fundraising\Models\Constituent;
use Awove\Nonprofit\Fundraising\Models\History;
use Awove\Nonprofit\Fundraising\Models\Pledge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Records
{
    public static function today(): string
    {
        return now(Installation::settings()['timezone'])->toDateString();
    }

    public static function clean(array $input): array
    {
        foreach ($input as $k => $v) {
            if (is_string($v)) {
                $input[$k] = trim($v) === '' ? null : trim($v);
            }
        }

        return $input;
    }

    public static function person(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id)?->only(['id', 'name']) : null;
    }

    public function version(Model $r, array $input, string $field = 'version'): void
    {
        $data = validator($input, [$field => 'required|integer|min:1'])->validate();
        abort_unless((int) $data[$field] === $r->getAttribute('version'), 409, 'This fundraising record changed. Reload before saving.');
    }

    public function history($u, Constituent $c, string $event, array $fields, ?int $pledge = null): void
    {
        History::create(['constituent_id' => $c->id, 'pledge_id' => $pledge, 'actor_id' => $u->id, 'event' => $event, 'changed_fields' => $fields]);
    }

    public function relationship($u, int $id): ?array
    {
        $r = Access::relationships($u)->find($id);
        if (! $r) {
            return null;
        }
        $data = app(IdentityRelationships::class)->describe($u, $r);

        return ['id' => $r->id, 'name' => $data['name'], 'type' => $data['type'], 'role' => $r->role, 'active' => $r->active && $r->archived_at === null];
    }

    public function save($u, array $input, ?int $id = null): Constituent
    {
        return DB::transaction(function () use ($u, $input, $id) {
            $u = Access::fresh($u);
            $r = $id ? Access::query($u)->lockForUpdate()->findOrFail($id) : null;
            if ($r) {
                Access::edit($u, $r);
                $this->version($r, $input);
            } else {
                abort_unless(Access::available($u) && $u->can(Access::PREFIX.'create'), 403);
            }
            $values = [...($r?->getAttributes() ?? ['owner_id' => $u->id]), ...self::clean($input)];
            $data = validator($values, ['relationship_id' => 'required|integer|min:1', 'owner_id' => 'nullable|integer|min:1', 'next_action' => 'nullable|string|max:10000', 'next_action_on' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:10000'])->validate();
            $relationship = Access::relationships($u)->findOrFail($data['relationship_id']);
            if ($r && $r->getAttribute('relationship_id') !== (int) $data['relationship_id']) {
                throw ValidationException::withMessages(['relationship_id' => 'A fundraising context retains its shared identity. Create a separate context for another identity.']);
            }
            if (! $r) {
                abort_unless($relationship->active && ! $relationship->archived_at, 403);
                if (Constituent::where('relationship_id', $relationship->id)->exists()) {
                    throw ValidationException::withMessages(['relationship_id' => 'This classification already has a retained fundraising context.']);
                }
            }
            $data['owner_id'] = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
            if (! $r || $r->owner_id !== $data['owner_id']) {
                abort_unless($data['owner_id'] === $u->id || $u->can(Access::PREFIX.'assign'), 403);
                if ($data['owner_id'] !== null) {
                    $owner = config('auth.providers.users.model')::find($data['owner_id']);
                    if (! Access::available($owner) || ! Access::relationships($owner)->whereKey($relationship->id)->exists()) {
                        throw ValidationException::withMessages(['owner_id' => 'Choose active staff who already have Fundraising and shared identity access to this classification. Assignment grants neither.']);
                    }
                }
            }
            if (filled($data['next_action'] ?? null) !== filled($data['next_action_on'] ?? null)) {
                throw ValidationException::withMessages(['next_action' => 'Enter both a next action and its date, or clear both.']);
            }
            if ($r) {
                $r->fill($data);
                $fields = array_keys($r->getDirty());
                if (! $fields) {
                    return $r;
                }
                $r->version++;
                $r->save();
            } else {
                $r = Constituent::create([...$data, 'created_by' => $u->id, 'version' => 1]);
                $fields = array_keys(array_filter($data, fn ($v) => $v !== null));
            }
            $this->history($u, $r, $id ? 'constituent.updated' : 'constituent.created', $fields);

            return $r;
        });
    }

    public function archive($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $c = Access::query($u)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $c);
            abort_unless($u->can(Access::PREFIX.'archive'), 403);
            $this->version($c, $input);
            $c->update(['archived_at' => now(), 'version' => $c->version + 1]);
            $this->history($u, $c, 'constituent.archived', ['archived_at']);
        });
    }

    public function pledge($u, int $constituentId, array $input, ?int $id = null): Pledge
    {
        return DB::transaction(function () use ($u, $constituentId, $input, $id) {
            $u = Access::fresh($u);
            Access::money($u, true);
            $c = Access::query($u)->lockForUpdate()->findOrFail($constituentId);
            Access::edit($u, $c);
            $this->version($c, $input, 'constituent_version');
            $p = $id ? Access::pledges($u)->where('constituent_id', $c->id)->lockForUpdate()->findOrFail($id) : null;
            if ($p) {
                abort_if($p->archived_at !== null, 403);
                $this->version($p, $input);
            } else {
                abort_unless($u->can(Access::PREFIX.'create'), 403);
            }
            $values = $p?->getAttributes() ?? [];
            unset($values['currency']);
            $data = validator([...$values, ...self::clean($input)], ['reference' => 'nullable|string|max:120', 'amount' => 'required', 'expected_on' => 'required|date_format:Y-m-d', 'restrictions' => 'nullable|string|max:10000', 'currency' => 'prohibited'])->validate();
            $data['amount'] = Money::amount($data['amount'], 'amount', true);
            app(Currency::class)->assertConsistent();
            if ($p && $p->getAttribute('currency') !== Currency::current()) {
                throw ValidationException::withMessages(['currency' => 'The retained pledge currency differs from the installation currency.']);
            }
            if ($p) {
                $p->fill($data);
                $fields = array_keys($p->getDirty());
                if (! $fields) {
                    return $p;
                }$p->version++;
                $p->save();
            } else {
                $p = Pledge::create([...$data, 'constituent_id' => $c->id, 'currency' => Currency::current(), 'created_by' => $u->id, 'version' => 1]);
                $fields = array_keys($data);
            }
            $c->update(['version' => $c->version + 1]);
            $this->history($u, $c, $id ? 'pledge.updated' : 'pledge.created', $fields, $p->id);

            return $p;
        });
    }

    public function archivePledge($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            Access::money($u, true);
            $raw = Access::pledges($u)->findOrFail($id);
            $c = Access::query($u)->lockForUpdate()->findOrFail($raw->constituent_id);
            Access::edit($u, $c);
            abort_unless($u->can(Access::PREFIX.'archive'), 403);
            $this->version($c, $input, 'constituent_version');
            $p = Access::pledges($u)->lockForUpdate()->findOrFail($id);
            $this->version($p, $input);
            abort_if($p->archived_at !== null, 403);
            $p->update(['archived_at' => now(), 'version' => $p->version + 1]);
            $c->update(['version' => $c->version + 1]);
            $this->history($u, $c, 'pledge.archived', ['archived_at'], $p->id);
        });
    }
}
