<?php

namespace Awove\Core\Services;

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\IdentityRelationship;
use Awove\Core\Models\IdentityRelationshipHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Contextual roles over the one shared directory, without granting related business-record access. */
class IdentityRelationships
{
    public const ROLES = ['customer' => 'Customer', 'supplier' => 'Supplier', 'donor' => 'Donor', 'partner' => 'Partner', 'prospect' => 'Prospect'];

    /** @return Builder<IdentityRelationship> */
    public function query($user, ?string $role = null, bool $includeArchived = false): Builder
    {
        $query = IdentityRelationship::query()->when(! $includeArchived, fn ($q) => $q->whereNull('archived_at'));
        $current = $user?->fresh();
        if (! $user || ! $user->active || ! $current || ! $current->active || (! $current->can('identities.classifications.view') && ! $current->can('identities.classifications.manage'))) {
            return $query->whereRaw('1=0');
        }
        $user = $current;
        abort_if($role !== null && ! array_key_exists($role, self::ROLES), 404);

        return $query->when($role, fn ($q) => $q->where('role', $role))->where(fn ($q) => $q
            ->whereIn('contact_id', Access::identities($user, Contact::class)->select('id'))
            ->orWhereIn('company_id', Access::identities($user, Company::class)->select('id')));
    }

    public function create($user, array $input): IdentityRelationship
    {
        $user = $this->authorizeWrite($user);
        $data = validator($input, [
            'contact_id' => 'nullable|integer|min:1', 'company_id' => 'nullable|integer|min:1',
            'role' => ['required', Rule::in(array_keys(self::ROLES))],
            ...$this->rules(),
        ])->validate();
        if (filled($data['contact_id'] ?? null) === filled($data['company_id'] ?? null)) {
            throw ValidationException::withMessages(['contact_id' => __('Choose exactly one existing contact or company.')]);
        }

        return DB::transaction(function () use ($user, $data) {
            $type = filled($data['contact_id'] ?? null) ? Contact::class : Company::class;
            $key = $type === Contact::class ? 'contact_id' : 'company_id';
            $identity = Access::identities($user, $type)->whereKey($data[$key])->lockForUpdate()->firstOrFail();
            if (IdentityRelationship::where($key, $identity->getKey())->where('role', $data['role'])->exists()) {
                throw ValidationException::withMessages(['role' => __('This identity already has that classification. Edit the existing record, including archived records.')]);
            }
            $data = $this->normalize($user, [...$data, $key => $identity->getKey(), 'responsible_id' => array_key_exists('responsible_id', $data) ? $data['responsible_id'] : $user->id]);
            $record = IdentityRelationship::create([...$data, 'created_by' => $user->id]);
            $this->recordHistory($user, $record, 'classification.created', array_keys($data));

            return $record;
        });
    }

    public function update($user, int $id, array $input): IdentityRelationship
    {
        $user = $this->authorizeWrite($user);

        return DB::transaction(function () use ($user, $id, $input) {
            $record = $this->query($user, null, true)->lockForUpdate()->findOrFail($id);
            $validated = validator($input, [
                'version' => 'required|integer|min:1', 'contact_id' => 'prohibited', 'company_id' => 'prohibited', 'role' => 'prohibited',
                ...$this->rules(),
            ])->validate();
            $this->checkVersion($record, (int) $validated['version']);
            unset($validated['version']);
            $data = $this->normalize($user, [...$record->only(['contact_id', 'company_id', 'responsible_id', 'main_contact_id', 'active', 'notes']), ...$validated], $record);
            $record->fill($data);
            $fields = array_keys($record->getDirty());
            if ($fields !== []) {
                $record->version++;
                $record->save();
                $this->recordHistory($user, $record, 'classification.updated', $fields);
            }

            return $record;
        });
    }

    public function archive($user, int $id, array $input): IdentityRelationship
    {
        $user = $this->authorizeWrite($user);

        return DB::transaction(function () use ($user, $id, $input) {
            $record = $this->query($user, null, true)->lockForUpdate()->findOrFail($id);
            $data = validator($input, ['version' => 'required|integer|min:1', 'archived' => 'required|boolean'])->validate();
            $this->checkVersion($record, (int) $data['version']);
            if ((bool) $record->archived_at !== (bool) $data['archived']) {
                $record->archived_at = $data['archived'] ? now() : null;
                $record->version++;
                $record->save();
                $this->recordHistory($user, $record, $data['archived'] ? 'classification.archived' : 'classification.restored', ['archived_at']);
            }

            return $record;
        });
    }

    public function describe($user, IdentityRelationship $record): array
    {
        $record = $this->query($user, null, true)->findOrFail($record->id);
        $user = $user->fresh();
        $identity = $record->contact_id ? $record->contact : $record->company;
        $main = $record->main_contact_id ? Access::identities($user, Contact::class)->find($record->main_contact_id) : null;

        return [
            ...$record->only(['id', 'contact_id', 'company_id', 'role', 'responsible_id', 'active', 'notes', 'version']),
            ...$identity->only(['name', 'email', 'phone']),
            'type' => $record->contact_id ? 'contact' : 'company',
            'profile_url' => '/people/'.($record->contact_id ? 'contacts/' : 'companies/').$identity->getKey(),
            'main_contact_id' => $main?->getKey(),
            'main_contact' => $main?->only(['id', 'name', 'email', 'phone']),
            'has_hidden_main_contact' => $record->main_contact_id !== null && $main === null,
            'responsible' => $record->responsible?->only(['id', 'name']),
            'archived_at' => $record->archived_at?->toISOString(),
        ];
    }

    public function history($user, IdentityRelationship $record): array
    {
        abort_unless($this->query($user, null, true)->whereKey($record->id)->exists(), 404);

        return IdentityRelationshipHistory::where('relationship_id', $record->id)->with('actor:id,name')->orderByDesc('version')->get()->map(fn (IdentityRelationshipHistory $entry) => [
            ...$entry->only(['id', 'event', 'changed_fields', 'version']),
            'actor' => $entry->actor?->only(['id', 'name']), 'created_at' => $entry->created_at->toISOString(),
        ])->all();
    }

    private function rules(): array
    {
        return ['responsible_id' => 'sometimes|nullable|integer|min:1', 'main_contact_id' => 'sometimes|nullable|integer|min:1', 'active' => 'sometimes|boolean', 'notes' => 'sometimes|nullable|string|max:10000'];
    }

    private function normalize($user, array $data, ?IdentityRelationship $record = null): array
    {
        foreach (['contact_id', 'company_id', 'main_contact_id', 'responsible_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }
        if (array_key_exists('notes', $data)) {
            $data['notes'] = filled($data['notes']) ? trim($data['notes']) : null;
        }
        if (! $record || $record->responsible_id !== ($data['responsible_id'] ?? null)) {
            abort_unless(($data['responsible_id'] ?? null) === $user->id || $user->can('identities.classifications.assign') || $user->can('users.manage'), 403);
            if (filled($data['responsible_id'] ?? null) && ! User::whereKey($data['responsible_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['responsible_id' => __('Choose an active responsible person.')]);
            }
        }
        // A redacted DTO must not silently clear a hidden historical main contact on save.
        if ($record && $record->main_contact_id !== null && empty($data['main_contact_id'])
            && ! Access::identities($user, Contact::class)->whereKey($record->main_contact_id)->exists()) {
            $data['main_contact_id'] = $record->main_contact_id;
        }
        // Preserve a hidden historical main contact unless an explicitly visible replacement is supplied.
        if (filled($data['main_contact_id'] ?? null) && (! $record || $record->main_contact_id !== $data['main_contact_id'])) {
            if (empty($data['company_id'])) {
                throw ValidationException::withMessages(['main_contact_id' => __('Main contact applies to a company classification.')]);
            }
            $main = Access::identities($user, Contact::class)->whereKey($data['main_contact_id'])->firstOrFail();
            if ($main->getAttribute('company_id') !== $data['company_id']) {
                throw ValidationException::withMessages(['main_contact_id' => __('Choose a contact belonging to this company.')]);
            }
        }

        return $data;
    }

    private function authorizeWrite($user): User
    {
        $current = $user?->fresh();
        abort_unless($user && $user->active && $current && $current->active && $current->can('identities.classifications.manage'), 403);

        return $current;
    }

    private function checkVersion(IdentityRelationship $record, int $version): void
    {
        if ($record->version !== $version) {
            throw ValidationException::withMessages(['version' => __('This classification changed. Reload it before saving.')]);
        }
    }

    private function recordHistory($actor, IdentityRelationship $record, string $event, array $fields): void
    {
        sort($fields);
        IdentityRelationshipHistory::create(['relationship_id' => $record->id, 'actor_id' => $actor->id, 'version' => $record->version, 'event' => $event, 'changed_fields' => $fields]);
    }
}
