<?php

namespace Awove\Core\Services;

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\Models\Contact;
use Awove\Core\Models\EmployeeReference;
use Awove\Core\Models\EmployeeReferenceHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Minimal shared references. Business modules own private information and parent-record access. */
class EmployeeReferences
{
    public function available($user): bool
    {
        $current = $user?->fresh();

        return $current && $current->active && $current->can('employees.references.view');
    }

    /** @return Builder<EmployeeReference> */
    public function query($user, bool $includeInactive = true): Builder
    {
        $query = EmployeeReference::query();
        if (! $this->available($user)) {
            return $query->whereRaw('1=0');
        }

        return $query->when(! $includeInactive, fn ($q) => $q->where('active', true));
    }

    public function find($user, int $id): EmployeeReference
    {
        $this->reader($user);

        return $this->query($user)->findOrFail($id);
    }

    /** @return list<array{id:int,name:string,active:bool}> */
    public function options($user, bool $includeInactive = false): array
    {
        return $this->query($user, $includeInactive)->orderBy('name')->orderBy('id')->get()->map(fn (EmployeeReference $record) => $record->toArray())->all();
    }

    /** Safe optional relation projection; never breaks an authorized parent after reference access is lost. */
    public function summary($user, ?int $id): ?array
    {
        return $id ? $this->query($user)->find($id)?->toArray() : null;
    }

    /** Domains choose whether inactive people may be selected, e.g. final payroll preparation. */
    public function assertSelectable($user, int $id, bool $allowInactive = false): EmployeeReference
    {
        $record = $this->find($user, $id);
        if (! $allowInactive && ! $record->getAttribute('active')) {
            throw ValidationException::withMessages(['employee_reference_id' => __('Choose an active employee reference.')]);
        }

        return $record;
    }

    public function create($user, array $input): EmployeeReference
    {
        return DB::transaction(function () use ($user, $input) {
            $user = $this->writer($user);
            $data = $this->data($user, $input);
            $record = EmployeeReference::create([...$data, 'version' => 1, 'created_by' => $user->id]);
            $this->recordHistory($user, $record, 'employee-reference.created', array_keys($data), ['contact_id' => ['from' => null, 'to' => $record->getAttribute('contact_id')], 'user_id' => ['from' => null, 'to' => $record->getAttribute('user_id')]]);

            return $record;
        });
    }

    public function update($user, int $id, array $input): EmployeeReference
    {
        return DB::transaction(function () use ($user, $id, $input) {
            $user = $this->writer($user);
            $record = EmployeeReference::lockForUpdate()->findOrFail($id);
            $this->version($record, $input);
            $old = $record->only(['contact_id', 'user_id']);
            $record->fill($this->data($user, $input, $record));
            $fields = array_keys($record->getDirty());
            if ($fields !== []) {
                $record->version++;
                $record->save();
                $links = [];
                foreach ($old as $key => $value) {
                    if (in_array($key, $fields, true)) {
                        $links[$key] = ['from' => $value, 'to' => $record->getAttribute($key)];
                    }
                }
                $this->recordHistory($user, $record, 'employee-reference.updated', $fields, $links);
            }

            return $record;
        });
    }

    public function setActive($user, int $id, int $version, bool $active): EmployeeReference
    {
        return $this->update($user, $id, ['version' => $version, 'active' => $active]);
    }

    /** Management-only additions are built explicitly; default model serialization stays minimal. */
    public function describe($user, EmployeeReference $record): array
    {
        $user = $this->reader($user);
        $record = $this->find($user, $record->id);
        $contact = $this->contact($user, $record->getAttribute('contact_id'));
        $account = $this->account($user, $record->getAttribute('user_id'));

        return [...$record->toArray(), 'version' => $record->version, 'contact_id' => $contact['id'] ?? null, 'contact' => $contact, 'has_hidden_contact' => $record->getAttribute('contact_id') !== null && $contact === null,
            'user_id' => $account['id'] ?? null, 'account' => $account, 'has_hidden_account' => $record->getAttribute('user_id') !== null && $account === null,
            'created_at' => $record->getAttribute('created_at')->toISOString(), 'updated_at' => $record->getAttribute('updated_at')->toISOString()];
    }

    public function history($user, int $id): array
    {
        $user = $this->reader($user);
        $this->find($user, $id);

        return EmployeeReferenceHistory::where('employee_reference_id', $id)->orderByDesc('version')->get()->map(function (EmployeeReferenceHistory $entry) use ($user) {
            $links = [];
            foreach ($entry->getAttribute('links') as $key => $change) {
                foreach (['from', 'to'] as $direction) {
                    $links[$key][$direction] = $key === 'contact_id' ? $this->contact($user, $change[$direction]) : $this->account($user, $change[$direction]);
                }
            }
            $actor = User::find($entry->getAttribute('actor_id'));

            return [...$entry->only(['id', 'version', 'event', 'changed_fields']), 'links' => $links, 'actor' => $actor?->only(['id', 'name']), 'created_at' => $entry->getAttribute('created_at')->toISOString()];
        })->all();
    }

    /** Explicit link choices, never inferred from matching names, contacts or accounts. */
    public function linkOptions($user): array
    {
        $user = $this->reader($user);
        $accounts = $user->can('users.manage') ? User::query() : User::whereKey($user->id);

        return ['contacts' => Access::identities($user, Contact::class)->orderBy('name')->get(['id', 'name'])->toArray(), 'accounts' => $accounts->orderBy('name')->get(['id', 'name'])->toArray()];
    }

    private function data(User $user, array $input, ?EmployeeReference $record = null): array
    {
        $data = validator($input, [
            'name' => ($record ? 'sometimes|' : '').'required|string|max:180', 'active' => 'sometimes|boolean',
            'contact_id' => 'sometimes|nullable|integer|min:1', 'user_id' => 'sometimes|nullable|integer|min:1',
            'contact_action' => ['sometimes', Rule::in(['keep', 'set', 'clear'])], 'user_action' => ['sometimes', Rule::in(['keep', 'set', 'clear'])],
            'version' => $record ? 'required|integer|min:1' : 'prohibited', 'created_by' => 'prohibited',
        ])->validate();
        $result = $record ? $record->only(['name', 'active', 'contact_id', 'user_id']) : ['name' => $data['name'], 'active' => true, 'contact_id' => null, 'user_id' => null];
        foreach (['name', 'active'] as $field) {
            if (array_key_exists($field, $data)) {
                $result[$field] = $data[$field];
            }
        }
        $result['name'] = trim($result['name']);
        if ($result['name'] === '') {
            throw ValidationException::withMessages(['name' => __('Enter an employee name.')]);
        }
        foreach (['contact_id' => 'contact_action', 'user_id' => 'user_action'] as $field => $actionKey) {
            $existing = $record?->getAttribute($field);
            $action = $data[$actionKey] ?? (filled($data[$field] ?? null) ? 'set' : 'keep');
            $value = $action === 'clear' ? null : ($action === 'set' ? ($data[$field] ?? null) : $existing);
            if ($action === 'set' && ! filled($value)) {
                throw ValidationException::withMessages([$field => __('Choose an existing record for the explicit link.')]);
            }
            $value = $value !== null ? (int) $value : null;
            if ($value !== $existing) {
                // Clearing hidden links is denied too; redacted-null ordinary edits preserve them.
                if ($existing !== null) {
                    abort_unless(($field === 'contact_id' ? $this->contact($user, $existing) : $this->account($user, $existing)) !== null, 403);
                }
                if ($value !== null) {
                    $linked = $field === 'contact_id' ? Access::identities($user, Contact::class)->whereKey($value)->lockForUpdate()->first() : $this->accountModel($user, $value, true);
                    abort_unless($linked !== null, 403);
                    if (EmployeeReference::where($field, $value)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                        throw ValidationException::withMessages([$field => __('This record is already linked to another employee reference. Choose that existing employee instead.')]);
                    }
                }
            }
            $result[$field] = $value;
        }

        return $result;
    }

    private function contact(User $user, ?int $id): ?array
    {
        $contact = $id ? Access::identities($user, Contact::class)->find($id) : null;

        return $contact ? [...$contact->only(['id', 'name']), 'profile_url' => '/people/contacts/'.$contact->id] : null;
    }

    private function account(User $user, ?int $id): ?array
    {
        return $id ? $this->accountModel($user, $id)?->only(['id', 'name']) : null;
    }

    private function accountModel(User $user, int $id, bool $lock = false): ?User
    {
        return User::when(! $user->can('users.manage'), fn ($q) => $q->whereKey($user->id))->when($lock, fn ($q) => $q->lockForUpdate())->find($id);
    }

    private function reader($user): User
    {
        $current = $user?->fresh();
        abort_unless($current && $current->active && $current->can('employees.references.view'), 403);

        return $current;
    }

    private function writer($user): User
    {
        $current = $this->reader($user);
        abort_unless($current->can('employees.references.manage'), 403);

        return $current;
    }

    private function version(EmployeeReference $record, array $input): void
    {
        $data = validator($input, ['version' => 'required|integer|min:1'])->validate();
        abort_unless($record->version === (int) $data['version'], 409, __('This employee reference changed. Reload before saving.'));
    }

    private function recordHistory(User $user, EmployeeReference $record, string $event, array $fields, array $links): void
    {
        EmployeeReferenceHistory::create(['employee_reference_id' => $record->id, 'actor_id' => $user->id, 'version' => $record->version, 'event' => $event, 'changed_fields' => $fields, 'links' => $links, 'created_at' => now()]);
    }
}
