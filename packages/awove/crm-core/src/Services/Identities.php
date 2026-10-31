<?php

namespace Awove\Core\Services;

use Awove\Core\Access;
use Awove\Core\ContactDetails;
use Awove\Core\Models\Company;
use Awove\Core\Models\CompanyNote;
use Awove\Core\Models\Contact;
use Awove\Core\Models\ContactNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Identities
{
    /** @return class-string<Contact>|class-string<Company> */
    public function model(string $type): string
    {
        return match ($type) {
            'contacts' => Contact::class,
            'companies' => Company::class,
            default => abort(404),
        };
    }

    public function create($user, string $type, array $input): Contact|Company
    {
        abort_unless($user->active && $user->can('leads.create'), 403);

        return DB::transaction(function () use ($user, $type, $input) {
            $model = $this->model($type);
            $data = $this->data($user, $type, $input);
            $notes = $data['notes'] ?? null;
            unset($data['notes']);
            $identity = $model::create([...$data, 'created_by' => $user->id]);
            if (filled($notes)) {
                $identity->notes()->create(['body' => trim($notes), 'author_id' => $user->id]);
            }

            app(IdentityHistory::class)->record($user, $identity, 'identity.created', array_keys($identity->getAttributes()));

            return $identity;
        });
    }

    public function update($user, string $type, int $id, array $input): Contact|Company
    {
        return DB::transaction(function () use ($user, $type, $id, $input) {
            $model = $this->model($type);
            $identity = $model::lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize('update', $identity);
            $identity->fill($this->data($user, $type, $input, $identity))->save();
            app(IdentityHistory::class)->record($user, $identity, 'identity.updated', array_keys($identity->getChanges()));

            return $identity;
        });
    }

    public function note($user, int $id, array $input): ContactNote
    {
        return $this->appendNote($user, Contact::class, $id, $input);
    }

    public function companyNote($user, int $id, array $input): CompanyNote
    {
        return $this->appendNote($user, Company::class, $id, $input);
    }

    /** @template T of Contact|Company
     * @param  class-string<T>  $model
     * @return ($model is class-string<Contact> ? ContactNote : CompanyNote)
     */
    private function appendNote($user, string $model, int $id, array $input): ContactNote|CompanyNote
    {
        return DB::transaction(function () use ($user, $model, $id, $input) {
            $identity = $model::lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize('update', $identity);
            $data = validator($input, ['body' => 'required|string|max:10000'])->validate();
            $body = trim($data['body']);
            if ($body === '') {
                throw ValidationException::withMessages(['body' => __('Enter a note.')]);
            }

            return $identity->notes()->create(['body' => $body, 'author_id' => $user->id]);
        });
    }

    private function data($user, string $type, array $input, Contact|Company|null $identity = null): array
    {
        if ($type === 'companies' && array_key_exists('notes', $input) && blank($input['notes'])) {
            // Preserve legacy company payloads that send empty contact-only fields.
            unset($input['notes']);
        }
        $rules = [
            'name' => ($identity ? 'sometimes|required' : 'required').'|string|max:120',
            'email' => 'nullable|email|max:160',
            'phone' => 'nullable|string|max:40',
            'company_id' => $type === 'contacts' ? 'nullable|integer|min:1' : 'prohibited',
            'accept_duplicate' => 'sometimes|boolean',
        ];
        if ($type === 'contacts') {
            $rules = [...$rules,
                'secondary_phone' => 'nullable|string|max:40',
                'secondary_phone_label' => ['nullable', Rule::in(array_keys(ContactDetails::PHONE_LABELS))],
                'preferred_contact_method' => ['nullable', Rule::in(array_keys(ContactDetails::METHODS))],
                'preferred_contact_times' => 'sometimes|array|max:4',
                'preferred_contact_times.*' => ['required', 'distinct', Rule::in(array_keys(ContactDetails::TIMES))],
                'preferred_contact_days' => 'sometimes|array|max:7',
                'preferred_contact_days.*' => ['required', 'distinct', Rule::in(array_keys(ContactDetails::DAYS))],
                'address' => 'nullable|string|max:500',
                'city' => 'nullable|string|max:120',
                'country' => 'nullable|string|max:120',
                'source' => ['nullable', Rule::in(array_keys(ContactDetails::SOURCES))],
                'notes' => $identity ? 'prohibited' : 'nullable|string|max:10000',
            ];
        }
        if ($type === 'companies') {
            foreach (array_diff(ContactDetails::ATTRIBUTES, ['name', 'email', 'phone', 'company_id']) as $field) {
                $rules[$field] = 'prohibited';
            }
            $rules['notes'] = $identity ? 'prohibited' : 'nullable|string|max:10000';
        }
        $data = validator($input, $rules)->validate();
        if (in_array('anytime', $data['preferred_contact_times'] ?? [], true) && count($data['preferred_contact_times']) > 1) {
            throw ValidationException::withMessages(['preferred_contact_times' => __('Choose Anytime or specific times of day.')]);
        }
        $acceptDuplicate = $data['accept_duplicate'] ?? false;
        unset($data['accept_duplicate']);
        if ($type === 'companies') {
            $data = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'notes']));
        }
        if ($identity) {
            unset($data['notes']);
        }
        $data = [...($identity?->only($type === 'contacts' ? ContactDetails::ATTRIBUTES : ['name', 'email', 'phone']) ?? []), ...$data];
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => __('Enter a name.')]);
        }
        $data['email'] = ! empty($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        foreach ($type === 'contacts' ? ['phone', 'secondary_phone', 'address', 'city', 'country', 'notes'] : ['phone', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filled($data[$field]) ? trim($data[$field]) : null;
            }
        }
        if ($type === 'contacts') {
            if (empty($data['secondary_phone'])) {
                $data['secondary_phone_label'] = null;
            }
            foreach (['preferred_contact_times', 'preferred_contact_days'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = array_values($data[$field] ?? []);
                }
            }
        }
        if (! empty($data['company_id']) && (! $identity || $identity->getAttribute('company_id') != $data['company_id'])) {
            abort_unless(Access::identities($user, Company::class)->whereKey($data['company_id'])->exists(), 403);
        }
        $model = $this->model($type);
        $duplicate = Access::identities($user, $model)->when($identity, fn ($q) => $q->whereKeyNot($identity->id))->where(function ($q) use ($data, $type) {
            $q->whereRaw('lower(name)=?', [mb_strtolower($data['name'])]);
            if ($data['email']) {
                $q->orWhereRaw('lower(email)=?', [$data['email']]);
            }
            foreach (array_filter([$data['phone'] ?? null, $type === 'contacts' ? ($data['secondary_phone'] ?? null) : null]) as $number) {
                $phone = preg_replace('/[^0-9]/', '', $number);
                if ($phone !== '') {
                    $q->orWhereRaw("regexp_replace(coalesce(phone,''), '[^0-9]', '', 'g') = ?", [$phone]);
                    if ($type === 'contacts') {
                        $q->orWhereRaw("regexp_replace(coalesce(secondary_phone,''), '[^0-9]', '', 'g') = ?", [$phone]);
                    }
                }
            }
        })->exists();
        if ($duplicate && ! $acceptDuplicate) {
            throw ValidationException::withMessages(['name' => __('Possible duplicate. Review existing records or confirm keeping a separate record.')]);
        }
        $searchValues = array_filter($data, fn ($value, $key) => $key !== 'notes' && is_scalar($value), ARRAY_FILTER_USE_BOTH);
        $data['search_key'] = Str::substr(mb_strtolower(preg_replace('/[^\p{L}\p{N}@.]+/u', '', implode(' ', $searchValues))), 0, 255);

        return $data;
    }
}
