<?php

namespace Awove\Knowledge;

use Awove\Core\Installation;
use Awove\Core\Services\DocumentLinks;
use Awove\Knowledge\Models\KnowledgeDocument;
use Awove\Knowledge\Models\KnowledgeHistory;
use Awove\Knowledge\Models\Renewal;
use Awove\Knowledge\Models\RenewalEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Records
{
    public const KINDS = ['procedure' => 'Procedure', 'material' => 'Reusable material', 'training' => 'Training'];

    public static function statuses(): array
    {
        return self::labels(['draft' => 'Draft', 'current' => 'Current', 'archived' => 'Archived'], 'knowledge_library_statuses');
    }

    public static function renewalStatuses(): array
    {
        return self::labels(['pending' => 'Pending', 'in-progress' => 'In progress', 'renewed' => 'Renewed'], 'knowledge_renewal_statuses');
    }

    private static function labels(array $defaults, string $key): array
    {
        $values = Installation::manifest()[$key] ?? [];
        if (! is_array($values)) {
            throw new \RuntimeException('Knowledge display labels must be a map.');
        }foreach (array_intersect_key($values, $defaults) as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('Knowledge display labels must be non-empty text of at most 100 characters.');
            }
        }

        return array_replace(array_map(fn ($label) => (string) __($label), $defaults), array_intersect_key($values, $defaults));
    }

    public function version(Model $r, array $input): void
    {
        $v = validator($input, ['version' => 'required|integer|min:1'])->validate();
        abort_unless((int) $v['version'] === $r->getAttribute('version'), 409, __('This Knowledge record changed. Reload before saving.'));
    }

    public function parentField(Model $r): string
    {
        return match (Access::section($r)) {
            'library' => 'entry_id','renewals' => 'renewal_id','systems' => 'system_id',default => abort(404)
        };
    }

    public function history($u, Model $r, string $event, array $fields = []): void
    {
        KnowledgeHistory::create([$this->parentField($r) => $r->getKey(), 'actor_id' => $u->id, 'event' => $event, 'changed_fields' => $fields]);
    }

    public function save($u, string $s, array $input, ?int $id = null): Model
    {
        return DB::transaction(function () use ($u, $s, $input, $id) {
            $u = Access::fresh($u);
            foreach ($input as $field => $value) {
                if (is_string($value)) {
                    $input[$field] = trim($value) === '' ? null : trim($value);
                }
            }
            $p = Access::prefix($s);
            $r = $id ? Access::query($u, $s)->lockForUpdate()->findOrFail($id) : null;
            if ($r) {
                Access::edit($u, $r);
                $this->version($r, $input);
            } else {
                abort_unless(Access::available($u, $s) && $u->can($p.'.create'), 403);
            }
            if ($s === 'systems' && (array_key_exists('login_url', $input) || array_key_exists('password_manager_reference', $input))) {
                abort_unless($u->can('knowledge.systems.references.view') && $u->can('knowledge.systems.references.edit'), 403);
            }
            validator($input, ['password' => 'prohibited', 'secret' => 'prohibited', 'token' => 'prohibited', 'api_key' => 'prohibited', 'secret_key' => 'prohibited', 'credentials' => 'prohibited'])->validate();
            $values = [...($r?->getAttributes() ?? ['owner_id' => $u->id]), ...$input];
            $common = ['owner_id' => 'nullable|integer|min:1'];
            $data = match ($s) {
                'library' => validator($values, [...$common, 'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'title' => 'required|string|max:180', 'category' => 'nullable|string|max:120', 'relevant_role' => ($values['kind'] ?? '') === 'training' ? 'required|string|max:160' : 'nullable|string|max:160', 'purpose' => ($values['kind'] ?? '') === 'material' ? 'required|string|max:10000' : 'nullable|string|max:10000', 'use_classification' => ($values['kind'] ?? '') === 'material' ? 'required|string|max:120' : 'nullable|string|max:120', 'revision' => 'nullable|string|max:120', 'revised_on' => 'nullable|date_format:Y-m-d', 'updated_on' => 'nullable|date_format:Y-m-d', 'status' => ['required', Rule::in(['draft', 'current'])]])->validate(),
                'renewals' => validator($values, [...$common, 'name' => 'required|string|max:180', 'provider' => 'required|string|max:180', 'expires_on' => 'required|date_format:Y-m-d', 'remind_on' => 'nullable|date_format:Y-m-d|before_or_equal:expires_on', 'status' => ['required', Rule::in(['pending', 'in-progress', 'renewed'])], 'notes' => 'nullable|string|max:10000'])->validate(),
                'systems' => validator($values, [...$common, 'name' => 'required|string|max:180', 'login_url' => 'nullable|string|max:2048', 'password_manager_reference' => 'nullable|string|max:300'])->validate(),default => abort(404)
            };
            foreach (['title', 'name', 'provider', 'category', 'relevant_role', 'revision', 'use_classification', 'password_manager_reference'] as $f) {
                if (isset($data[$f])) {
                    $data[$f] = trim($data[$f]);
                    if (in_array($f, ['title', 'name', 'provider'], true) && $data[$f] === '') {
                        throw ValidationException::withMessages([$f => __('Enter a value.')]);
                    }
                }
            }
            if ($s === 'library') {
                if ($r && $data['kind'] !== $r->getAttribute('kind')) {
                    throw ValidationException::withMessages(['kind' => __('The record retains its procedure, material or training purpose.')]);
                }
                if ($data['status'] === 'current' && $data['kind'] === 'procedure' && empty($data['revision']) && empty($data['revised_on'])) {
                    throw ValidationException::withMessages(['revision' => __('Enter a current version or revision date for a current procedure.')]);
                }
            }
            if ($s === 'renewals' && $data['status'] === 'renewed' && (! $r || $r->getAttribute('status') !== 'renewed')) {
                throw ValidationException::withMessages(['status' => __('Use Record renewal to retain the dated renewal history.')]);
            }
            if ($s === 'systems') {
                $data['login_url'] = app(DocumentLinks::class)->validate($data['login_url'] ?? null, 'login_url');
                $this->reference($data['password_manager_reference'] ?? null);
                if ($data['login_url'] && (parse_url($data['login_url'], PHP_URL_QUERY) !== null || parse_url($data['login_url'], PHP_URL_FRAGMENT) !== null)) {
                    throw ValidationException::withMessages(['login_url' => __('Use the public HTTPS login page without a query string or fragment.')]);
                }
            }
            $owner = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
            $data['owner_id'] = $owner;
            if (! $r || $owner !== $r->getAttribute('owner_id')) {
                abort_unless($owner === $u->id || $u->can($p.'.assign'), 403);
                if ($owner !== null) {
                    $person = config('auth.providers.users.model')::find($owner);
                    if (! Access::available($person, $s)) {
                        throw ValidationException::withMessages(['owner_id' => __('Choose an active owner who already has the appropriate Knowledge access.')]);
                    }
                }
            }
            if ($r) {
                $r->fill($data);
                $fields = array_keys($r->getDirty());
                if (! $fields) {
                    return $r;
                }$r->version++;
                $r->save();
            } else {
                $m = Access::MODELS[$s];
                $r = $m::create([...$data, 'created_by' => $u->id, 'version' => 1]);
                $fields = array_keys(array_filter($data, fn ($v) => $v !== null && $v !== ''));
            }
            $this->history($u, $r, $id ? 'updated' : 'created', $fields);
            if (! empty($input['document_url'])) {
                $this->document($u, $s, $r->getKey(), ['name' => filled($input['document_name'] ?? null) ? $input['document_name'] : ($data['title'] ?? $data['name']), 'url' => $input['document_url']]);
            }

            return $r->refresh();
        });
    }

    private function reference(?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        // Store an opaque item locator only: no URLs, credentials, credential-labelled
        // assignments, PEM blocks or common API-token forms are accepted here.
        if (! preg_match('/^[\pL\pN][\pL\pN ._\/#:-]{0,299}$/u', $value) || preg_match('/(?:password|passwd|secret|token|api[-_ ]?key)\s*[:=]|-----BEGIN|\b(?:sk-|ghp_|xox[baprs]-)|@|:\/\//i', $value)) {
            throw ValidationException::withMessages(['password_manager_reference' => __('Enter only the password-manager item name or opaque item ID. Do not enter credentials, secret keys or a URL.')]);
        }
    }

    public function archive($u, string $s, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $s, $id, $input) {
            $u = Access::fresh($u);
            $r = Access::query($u, $s)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $r);
            abort_unless($u->can(Access::prefix($s).'.archive'), 403);
            $this->version($r, $input);
            $data = ['archived_at' => now(), 'version' => $r->version + 1];
            if ($s === 'library') {
                $data['status'] = 'archived';
            }$r->update($data);
            $this->history($u, $r, 'archived', ['archived_at']);
        });
    }

    public function document($u, string $s, int $id, array $input): KnowledgeDocument
    {
        return DB::transaction(function () use ($u, $s, $id, $input) {
            $u = Access::fresh($u);
            abort_unless(in_array($s, ['library', 'renewals'], true) && $u->can('knowledge.documents.view') && $u->can('knowledge.documents.manage'), 403);
            $r = Access::query($u, $s)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $r);
            $data = validator($input, ['name' => 'required|string|max:180', 'url' => 'required|string|max:2048'])->validate();
            $data['name'] = trim($data['name']);
            if ($data['name'] === '') {
                throw ValidationException::withMessages(['name' => __('Enter a document name.')]);
            }
            $data['url'] = app(DocumentLinks::class)->validate($data['url'], 'url');
            $doc = KnowledgeDocument::create([...$data, $this->parentField($r) => $id, 'created_by' => $u->id, 'version' => 1]);
            $r->update(['version' => $r->version + 1]);
            $this->history($u, $r, 'document.added', ['documents']);

            return $doc;
        });
    }

    public function archiveDocument($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            abort_unless($u->can('knowledge.documents.view') && $u->can('knowledge.documents.manage'), 403);
            $doc = KnowledgeDocument::lockForUpdate()->findOrFail($id);
            $s = $doc->entry_id ? 'library' : 'renewals';
            $r = Access::query($u, $s)->lockForUpdate()->findOrFail($doc->entry_id ?? $doc->renewal_id);
            Access::edit($u, $r);
            $this->version($doc, $input);
            abort_if($doc->archived_at !== null, 403);
            $doc->update(['archived_at' => now(), 'version' => $doc->version + 1]);
            $r->update(['version' => $r->version + 1]);
            $this->history($u, $r, 'document.archived', ['documents']);
        });
    }

    public function renew($u, int $id, array $input): Renewal
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $r = Access::query($u, 'renewals')->lockForUpdate()->findOrFail($id);
            Access::edit($u, $r);
            $this->version($r, $input);
            abort_if($r->status === 'renewed', 409, __('This renewal was already recorded.'));
            $data = validator($input, ['renewed_on' => 'required|date_format:Y-m-d|before_or_equal:'.now(Installation::settings()['timezone'])->toDateString(), 'next_expiry_on' => 'nullable|date_format:Y-m-d|after:renewed_on|after:'.$r->toArray()['expires_on'], 'next_remind_on' => 'nullable|date_format:Y-m-d|before_or_equal:next_expiry_on', 'summary' => 'nullable|string|max:10000'])->validate();
            if (! empty($data['next_remind_on']) && empty($data['next_expiry_on'])) {
                throw ValidationException::withMessages(['next_remind_on' => __('A next reminder requires an explicitly entered next expiry date.')]);
            }RenewalEvent::create(['renewal_id' => $id, 'previous_expiry_on' => $r->toArray()['expires_on'], 'renewed_on' => $data['renewed_on'], 'next_expiry_on' => $data['next_expiry_on'] ?? null, 'summary' => $data['summary'] ?? null, 'actor_id' => $u->id]);
            $r->update(['expires_on' => $data['next_expiry_on'] ?? $r->toArray()['expires_on'], 'remind_on' => $data['next_remind_on'] ?? null, 'status' => empty($data['next_expiry_on']) ? 'renewed' : 'pending', 'version' => $r->version + 1]);
            $this->history($u, $r, 'renewed', ['expires_on', 'remind_on', 'status']);

            return $r;
        });
    }
}
