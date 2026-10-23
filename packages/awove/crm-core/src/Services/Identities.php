<?php

namespace Awove\Core\Services;

use Awove\Core\Access;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

            return $model::create([...$data, 'created_by' => $user->id]);
        });
    }

    public function update($user, string $type, int $id, array $input): Contact|Company
    {
        return DB::transaction(function () use ($user, $type, $id, $input) {
            $model = $this->model($type);
            $identity = $model::lockForUpdate()->findOrFail($id);
            Gate::forUser($user)->authorize('update', $identity);
            $identity->fill($this->data($user, $type, $input, $identity))->save();

            return $identity;
        });
    }

    private function data($user, string $type, array $input, Contact|Company|null $identity = null): array
    {
        $data = validator($input, ['name' => ($identity ? 'sometimes|required' : 'required').'|string|max:120', 'email' => 'nullable|email|max:160', 'phone' => 'nullable|string|max:40', 'company_id' => $type === 'contacts' ? 'nullable|integer|min:1' : 'prohibited', 'accept_duplicate' => 'sometimes|boolean'])->validate();
        $acceptDuplicate = $data['accept_duplicate'] ?? false;
        unset($data['accept_duplicate']);
        if ($type === 'companies') {
            unset($data['company_id']);
        }
        $data = [...($identity?->only($type === 'contacts' ? ['name', 'email', 'phone', 'company_id'] : ['name', 'email', 'phone']) ?? []), ...$data];
        $data['name'] = trim($data['name']);
        if ($data['name'] === '') {
            throw ValidationException::withMessages(['name' => 'Enter a name.']);
        }
        $data['email'] = ! empty($data['email']) ? mb_strtolower(trim($data['email'])) : null;
        $data['phone'] = ! empty($data['phone']) ? trim($data['phone']) : null;
        if (! empty($data['company_id']) && (! $identity || $identity->getAttribute('company_id') != $data['company_id'])) {
            abort_unless(Access::identities($user, Company::class)->whereKey($data['company_id'])->exists(), 403);
        }
        $model = $this->model($type);
        $duplicate = Access::identities($user, $model)->when($identity, fn ($q) => $q->whereKeyNot($identity->id))->where(function ($q) use ($data) {
            $q->whereRaw('lower(name)=?', [mb_strtolower($data['name'])]);
            if ($data['email']) {
                $q->orWhereRaw('lower(email)=?', [$data['email']]);
            }
            $phone = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
            if ($phone !== '') {
                $q->orWhereRaw("regexp_replace(coalesce(phone,''), '[^0-9]', '', 'g') = ?", [$phone]);
            }
        })->exists();
        if ($duplicate && ! $acceptDuplicate) {
            throw ValidationException::withMessages(['name' => 'Possible duplicate. Review existing records or confirm keeping a separate record.']);
        }
        $data['search_key'] = Str::substr(mb_strtolower(preg_replace('/[^\p{L}\p{N}@.]+/u', '', implode(' ', array_filter($data)))), 0, 255);

        return $data;
    }
}
