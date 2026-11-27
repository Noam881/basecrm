<?php

namespace Awove\Vault;

use Awove\Core\Access as CoreAccess;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Tables\Query;
use Awove\Vault\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Records
{
    public function listing(Request $request): array
    {
        $user = Access::actor($request->user());
        Access::authorize($user, 'list');
        $query = Access::items($user);
        if ($request->filled('customer_type') || $request->filled('customer_id')) {
            $customer = $request->validate(['customer_type' => ['required', Rule::in(['contacts', 'companies'])], 'customer_id' => 'required|integer|min:1']);
            $parent = Access::parent($customer['customer_type'], $customer['customer_id']);
            Access::authorize($user, 'list', parent: $parent);
            $query->where($customer['customer_type'] === 'contacts' ? 'contact_id' : 'company_id', $parent->id);
        }
        $search = validator($request->only('q'), ['q' => 'nullable|string|max:160'])->validate()['q'] ?? '';
        $search = trim($search);
        $types = array_keys(array_filter(Schema::TYPES, fn ($label) => $search !== ''
            && (mb_stripos($label, $search) !== false || mb_stripos((string) __($label), $search) !== false)));
        $tableRequest = $request;
        if ($types !== []) {
            // The shared table searches raw enum IDs. Extend only this module's
            // metadata predicate with localized type labels, never decrypted values.
            $columns = app(Tables::class)->columns('vault.items', $user);
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($matches) use ($columns, $term, $types) {
                $matches->whereIn('vault_items.type', $types);
                foreach ($columns as $column) {
                    $matches->orWhereRaw("CAST(({$column->expression}) AS TEXT) ILIKE ?", [$term]);
                }
            });
            $tableRequest = clone $request;
            $tableRequest->setJson(clone $request->json());
            $tableRequest->merge(['q' => '']);
        }
        $table = app(Query::class)->run($query, 'vault.items', $tableRequest);
        $table['state']['q'] = $search;
        $table['page']->setCollection($table['page']->getCollection()->map(fn (Item $item) => [...$this->metadata($item, $user), 'cells' => $item->getAttribute('cells')]));

        return $table;
    }

    public function options($user): array
    {
        $user = Access::actor($user);
        Access::authorize($user, 'list');
        $customers = [];
        foreach (['contacts' => Contact::class, 'companies' => Company::class] as $type => $model) {
            foreach (CoreAccess::identities($user, $model)->orderBy('name')->get(['id', 'name']) as $record) {
                $customers[] = ['type' => $type, 'id' => $record->id, 'name' => $record->getAttribute('name')];
            }
        }

        return ['customers' => $customers, 'types' => Schema::templates(), 'actions' => $this->actions($user)];
    }

    public function find(string $id): Item
    {
        return Item::where('installation_id', Installation::manifest()['id'])->findOrFail($id);
    }

    public function metadata(Item $item, $user, string $action = 'list'): array
    {
        Access::authorize($user, $action, $item);
        $parent = Access::itemParent($item);

        return ['id' => $item->id, 'title' => $item->title, 'type' => $item->type, 'level' => $item->level, 'version' => $item->version,
            'customer_type' => $item->contact_id ? 'contacts' : 'companies', 'customer_id' => $parent->id,
            'customer_name' => $parent->getAttribute('name'), 'created_at' => $item->created_at->toISOString(), 'updated_at' => $item->updated_at->toISOString(),
            'actions' => $this->actions($user, $item)];
    }

    private function actions($user, ?Item $item = null): array
    {
        $actions = [];
        foreach (['create', 'read', 'update', 'delete', 'change-level', 'transfer', 'files.upload', 'files.download', 'files.delete'] as $action) {
            $actions[str_replace(['-', '.'], '_', $action)] = Access::allowed($user, $action, $item);
        }

        return $actions;
    }

    public function create(Request $request): Item
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $data = $this->data($request);
        $parent = Access::parent($data['customer_type'], $data['customer_id']);
        Access::authorize($user, 'create', parent: $parent);
        abort_unless($data['level'] === 2 || Access::restricted($user), 403);

        return DB::transaction(function () use ($user, $parent, $data, $request) {
            $parent = $parent->newQuery()->lockForUpdate()->findOrFail($parent->id);
            Access::authorize($user, 'create', parent: $parent);
            app(Unlocks::class)->requireUnlocked($request);
            $id = (string) Str::uuid();
            $sealed = app(Cipher::class)->encrypt(json_encode($data['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'item:'.$id);
            $item = Item::create(['id' => $id, 'installation_id' => Installation::manifest()['id'],
                'contact_id' => $parent instanceof Contact ? $parent->id : null, 'company_id' => $parent instanceof Company ? $parent->id : null,
                'title' => $data['title'], 'type' => $data['type'], 'level' => $data['level'], 'version' => 1,
                'encrypted_payload' => $sealed['ciphertext'], 'key_version' => $sealed['key_version'], 'created_by' => $user->id, 'updated_by' => $user->id]);
            AuditLog::record($user, 'item.created', $item->id);
            app(Unlocks::class)->requireUnlocked($request);
            Access::authorize($user, 'create', $item);

            return $item;
        });
    }

    public function update(Request $request, string $id): Item
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $data = $this->data($request, true);

        return DB::transaction(function () use ($request, $user, $data, $id) {
            $item = Item::where('installation_id', Installation::manifest()['id'])->lockForUpdate()->findOrFail($id);
            Access::authorize($user, 'update', $item);
            $this->version($item, $data['version']);
            $parent = Access::parent($data['customer_type'], $data['customer_id']);
            $moved = $parent->getKey() !== ($item->contact_id ?: $item->company_id) || ($parent instanceof Contact) !== ($item->contact_id !== null);
            if ($moved) {
                Access::authorize($user, 'transfer', $item);
                Access::authorize($user, 'transfer', parent: $parent);
            }
            if ($item->level !== $data['level']) {
                Access::authorize($user, 'change-level', $item);
                abort_unless($data['level'] === 2 || Access::restricted($user), 403);
            }
            if ($item->type !== $data['type']) {
                $old = $this->decrypt($item);
                if (array_diff(array_column($old['fields'], 'id'), array_column($data['payload']['fields'], 'id'))) {
                    throw ValidationException::withMessages(['type' => __('Changing the item type keeps existing fields. Remove fields in a separate edit.')]);
                }
            }
            app(Unlocks::class)->requireUnlocked($request);
            $sealed = app(Cipher::class)->encrypt(json_encode($data['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'item:'.$item->id);
            $item->update(['title' => $data['title'], 'type' => $data['type'], 'level' => $data['level'],
                'contact_id' => $parent instanceof Contact ? $parent->id : null, 'company_id' => $parent instanceof Company ? $parent->id : null,
                'encrypted_payload' => $sealed['ciphertext'], 'key_version' => $sealed['key_version'], 'version' => $item->version + 1, 'updated_by' => $user->id]);
            AuditLog::record($user, 'item.updated', $item->id);
            if ($moved) {
                AuditLog::record($user, 'item.transferred', $item->id);
            }
            if ($item->wasChanged('level')) {
                AuditLog::record($user, 'item.level_changed', $item->id);
            }
            app(Unlocks::class)->requireUnlocked($request);
            Access::authorize($user, 'update', $item->fresh());

            return $item;
        });
    }

    public function remove(Request $request, string $id): void
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $data = $request->validate(['version' => 'required|integer|min:1']);
        DB::transaction(function () use ($request, $user, $data, $id) {
            $item = Item::where('installation_id', Installation::manifest()['id'])->lockForUpdate()->findOrFail($id);
            Access::authorize($user, 'delete', $item);
            $this->version($item, $data['version']);
            app(Unlocks::class)->requireUnlocked($request);
            $item->update(['version' => $item->version + 1, 'updated_by' => $user->id]);
            $item->delete();
            AuditLog::record($user, 'item.deleted', $item->id);
            app(Unlocks::class)->requireUnlocked($request);
        });
    }

    public function content(Request $request, string $id): array
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $item = $this->find($id);
        Access::authorize($user, 'read', $item);
        $payload = $this->decrypt($item);
        $files = app(Files::class)->metadata($request, $item);
        // Final server check before returning plaintext; activity never extends the grant.
        app(Unlocks::class)->requireUnlocked($request);
        Access::authorize($user, 'read', $this->find($id));
        AuditLog::record($user, 'item.read', $item->id);

        return ['item' => $this->metadata($item, $user, 'read'), 'payload' => $payload, 'files' => $files,
            'source_export' => Models\ImportEntry::where('installation_id', Installation::manifest()['id'])->where('item_id', $item->id)->where('state', 'committed')->exists()];
    }

    public function decrypt(Item $item): array
    {
        $plain = app(Cipher::class)->decrypt($item->encrypted_payload, $item->key_version, 'item:'.$item->id);
        try {
            $payload = json_decode($plain, true, 64, JSON_THROW_ON_ERROR);
        } finally {
            sodium_memzero($plain);
        }
        abort_unless(is_array($payload) && is_array($payload['fields'] ?? null), 503, __('Vault encryption is unavailable. Contact the installation operator.'));

        return $payload;
    }

    public function version(Item $item, int $version): void
    {
        abort_unless($item->version === $version, 409, __('This Vault item changed. Reload it before saving.'));
    }

    private function data(Request $request, bool $update = false): array
    {
        $data = $request->validate(['customer_type' => ['required', Rule::in(['contacts', 'companies'])], 'customer_id' => 'required|integer|min:1',
            'title' => 'required|string|max:160', 'type' => ['required', Rule::in(array_keys(Schema::TYPES))], 'level' => ['required', 'integer', Rule::in([1, 2])],
            'payload' => 'required|array', 'version' => $update ? 'required|integer|min:1' : 'prohibited']);
        $data['level'] = (int) $data['level'];
        if ($update) {
            $data['version'] = (int) $data['version'];
        }
        if (trim($data['title']) === '' || preg_match('/\d(?:[ -]?\d){12,}|-----BEGIN.*PRIVATE KEY/u', $data['title'])) {
            throw ValidationException::withMessages(['title' => __('Use a descriptive title, never a password, full card number or private key.')]);
        }
        $data['payload'] = Schema::payload($data['payload']);

        return $data;
    }
}
