<?php

namespace Awove\Finance\Payroll;

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\EmployeeReference;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\EmployeeReferences;
use Awove\Finance\Access;
use Awove\Finance\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Preparations
{
    public const STATUSES = ['pending-input' => 'Pending input', 'ready-for-review' => 'Ready for review', 'sent-to-payroll' => 'Sent to payroll'];

    public static function statuses(): array
    {
        $labels = array_replace(array_map(fn ($label) => (string) __($label), self::STATUSES), array_intersect_key(Installation::manifest()['payroll_statuses'] ?? [], self::STATUSES));
        foreach ($labels as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('Payroll status labels must be nonempty strings up to 100 characters.');
            }
        }

        return $labels;
    }

    /** @return Builder<Preparation> */
    public function query($user, bool $archived = false): Builder
    {
        return Access::scope(Preparation::query(), $user, 'payroll')->when(! $archived, fn ($q) => $q->whereNull('archived_at'));
    }

    public function find($user, int $id): Preparation
    {
        Access::actor($user, 'payroll');

        return $this->query($user, true)->findOrFail($id);
    }

    public function editable($user, Preparation $record): bool
    {
        $user = Access::actor($user, 'payroll');

        return $record->getAttribute('archived_at') === null && ($user->can('finance.payroll.edit.all') || ($record->getAttribute('owner_id') === $user->id && $user->can('finance.payroll.edit.own')));
    }

    public function create($user, array $input): Preparation
    {
        return DB::transaction(function () use ($user, $input) {
            $user = Access::actor($user, 'payroll');
            abort_unless($user->can('finance.payroll.create'), 403);
            Currency::assertConsistent();
            $data = $this->data($user, $input);
            // Serialize same-employee creates, including archived period identities.
            app(EmployeeReferences::class)->assertSelectable($user, $data['employee_reference_id'], true)->newQuery()->whereKey($data['employee_reference_id'])->lockForUpdate()->firstOrFail();
            $this->uniquePeriod($data);
            $record = Preparation::create([...$data, 'version' => 1, 'created_by' => $user->id]);
            $this->historyEntry($user, $record, 'payroll.created', ['changed_fields' => array_keys($data)]);

            return $record;
        });
    }

    public function update($user, int $id, array $input): Preparation
    {
        return DB::transaction(function () use ($user, $id, $input) {
            $user = Access::actor($user, 'payroll');
            $record = Preparation::lockForUpdate()->findOrFail($id);
            Access::edit($user, $record, 'payroll');
            $this->version($record, $input);
            Currency::assertConsistent();
            $data = $this->data($user, $input, $record);
            EmployeeReference::whereKey($data['employee_reference_id'])->lockForUpdate()->firstOrFail();
            $this->uniquePeriod($data, $record->id);
            $previous = $record->only(['status', 'sent_on']);
            $record->fill($data);
            $fields = array_keys($record->getDirty());
            if ($fields !== []) {
                $record->version++;
                $record->save();
                $this->historyEntry($user, $record, 'payroll.updated', ['changed_fields' => $fields, 'previous_status' => $previous['status'], 'previous_sent_on' => $previous['sent_on']]);
            }

            return $record;
        });
    }

    public function archive($user, int $id, array $input): Preparation
    {
        return DB::transaction(function () use ($user, $id, $input) {
            $user = Access::actor($user, 'payroll');
            $record = Preparation::lockForUpdate()->findOrFail($id);
            Access::edit($user, $record, 'payroll');
            abort_unless($user->can('finance.payroll.archive'), 403);
            $this->version($record, $input);
            $record->forceFill(['archived_at' => now(), 'version' => $record->version + 1])->save();
            $this->historyEntry($user, $record, 'payroll.archived', ['changed_fields' => ['archived_at']]);

            return $record;
        });
    }

    public function addDocument($user, int $id, array $input): Document
    {
        return DB::transaction(function () use ($user, $id, $input) {
            $user = Access::actor($user, 'payroll');
            $record = Preparation::lockForUpdate()->findOrFail($id);
            Access::edit($user, $record, 'payroll');
            $this->version($record, $input);
            $data = validator($input, ['label' => 'required|string|max:180', 'url' => 'required|string|max:2048'])->validate();
            $data['url'] = app(DocumentLinks::class)->validate($data['url'], 'url');
            $document = Document::create([...$data, 'preparation_id' => $id, 'added_by' => $user->id, 'created_at' => now()]);
            $record->version++;
            $record->save();
            $this->historyEntry($user, $record, 'payroll.document-added', ['document_id' => $document->id]);

            return $document;
        });
    }

    public function documents($user, int $id): array
    {
        $this->find($user, $id);

        return Document::where('preparation_id', $id)->orderByDesc('id')->get()->map(fn (Document $d) => ['id' => $d->id, 'label' => $d->getAttribute('label'), 'created_at' => $d->getAttribute('created_at')->toISOString(), 'href' => '/finance/payroll/'.$id.'/documents/'.$d->id])->all();
    }

    public function history($user, int $id): array
    {
        $this->find($user, $id);

        return History::where('preparation_id', $id)->orderByDesc('version')->get()->map(fn (History $h) => ['id' => $h->id, 'event' => $h->getAttribute('event'), 'version' => $h->version, 'details' => $h->getAttribute('details'), 'actor' => User::find($h->getAttribute('actor_id'))?->only(['id', 'name']), 'created_at' => $h->getAttribute('created_at')->toISOString()])->all();
    }

    public function describe($user, Preparation $record): array
    {
        $record = $this->find($user, $record->id);
        $employee = app(EmployeeReferences::class)->summary($user, $record->getAttribute('employee_reference_id'));

        return [...$record->only(['id', 'owner_id', 'status', 'currency', 'input_schema', 'inputs', 'notes', 'version', 'cells']), 'employee_reference_id' => $employee['id'] ?? null, 'employee' => $employee, 'period_start_on' => $record->getAttribute('period_start_on')->toDateString(), 'period_end_on' => $record->getAttribute('period_end_on')->toDateString(), 'sent_on' => $record->getAttribute('sent_on')?->toDateString(), 'archived_at' => $record->getAttribute('archived_at')?->toISOString(), 'created_at' => $record->getAttribute('created_at')->toISOString(), 'updated_at' => $record->getAttribute('updated_at')->toISOString()];
    }

    private function data(User $user, array $input, ?Preparation $record = null): array
    {
        if ($record && array_key_exists('employee_reference_id', $input) && $input['employee_reference_id'] === null && ! app(EmployeeReferences::class)->summary($user, $record->getAttribute('employee_reference_id'))) {
            unset($input['employee_reference_id']);
        }
        $rules = ['employee_reference_id' => ($record ? 'sometimes|' : '').'required|integer|min:1', 'owner_id' => 'sometimes|nullable|integer|min:1', 'period_start_on' => ($record ? 'sometimes|' : '').'required|date_format:Y-m-d', 'period_end_on' => ($record ? 'sometimes|' : '').'required|date_format:Y-m-d', 'status' => ['sometimes', Rule::in(array_keys(self::STATUSES))], 'inputs' => 'sometimes|array', 'notes' => 'sometimes|nullable|string|max:20000', 'sent_on' => 'sometimes|nullable|date_format:Y-m-d', 'input_schema' => 'prohibited', 'currency' => 'prohibited', 'created_by' => 'prohibited'];
        $data = validator($input, $rules)->validate();
        $current = $record ? $record->only(['employee_reference_id', 'owner_id', 'period_start_on', 'period_end_on', 'status', 'input_schema', 'inputs', 'notes', 'sent_on', 'currency']) : ['owner_id' => $user->id, 'status' => 'pending-input', 'input_schema' => app(Inputs::class)->configured(), 'inputs' => [], 'notes' => null, 'sent_on' => null, 'currency' => Currency::current()];
        $data = [...$current, ...$data];
        // Redacted employee IDs on ordinary profile edits retain the historical relationship.
        if ($record && ! app(EmployeeReferences::class)->summary($user, $record->getAttribute('employee_reference_id')) && ! filled($input['employee_reference_id'] ?? null)) {
            $data['employee_reference_id'] = $record->getAttribute('employee_reference_id');
        }
        $data['employee_reference_id'] = (int) $data['employee_reference_id'];
        if (! $record || $data['employee_reference_id'] !== $record->getAttribute('employee_reference_id')) {
            app(EmployeeReferences::class)->assertSelectable($user, $data['employee_reference_id'], true);
        }
        $data['owner_id'] = $data['owner_id'] !== null ? (int) $data['owner_id'] : null;
        if ($data['owner_id'] !== ($record ? $record->getAttribute('owner_id') : $user->id)) {
            abort_unless($user->can('finance.payroll.assign'), 403);
            if ($data['owner_id'] !== null) {
                $owner = User::findOrFail($data['owner_id']);
                abort_unless(Access::available($owner, 'payroll'), 403);
            }
        }
        foreach (['period_start_on', 'period_end_on', 'sent_on'] as $field) {
            if (($data[$field] ?? null) instanceof \DateTimeInterface) {
                $data[$field] = $data[$field]->format('Y-m-d');
            }
        }
        if ($data['period_end_on'] < $data['period_start_on']) {
            throw ValidationException::withMessages(['period_end_on' => __('Period end must be on or after its start.')]);
        }
        if ($data['status'] === 'sent-to-payroll' && ! $data['sent_on']) {
            throw ValidationException::withMessages(['sent_on' => __('Record the date you manually sent the preparation to payroll.')]);
        }
        if ($data['status'] !== 'sent-to-payroll') {
            $data['sent_on'] = null;
        }
        $values = $record ? [...$record->getAttribute('inputs'), ...($input['inputs'] ?? [])] : ($input['inputs'] ?? []);
        $data['inputs'] = app(Inputs::class)->validate($data['input_schema'], $values, $data['status'] !== 'pending-input');

        return $data;
    }

    private function uniquePeriod(array $data, ?int $except = null): void
    {
        if (Preparation::where('employee_reference_id', $data['employee_reference_id'])->whereDate('period_start_on', $data['period_start_on'])->whereDate('period_end_on', $data['period_end_on'])->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['period_start_on' => __('This employee already has a preparation for this exact pay period, including archived records.')]);
        }
    }

    private function version(Preparation $record, array $input): void
    {
        $data = validator($input, ['version' => 'required|integer|min:1'])->validate();
        abort_unless($record->version === (int) $data['version'], 409, __('This preparation changed. Reload before saving.'));
    }

    private function historyEntry(User $user, Preparation $record, string $event, array $details): void
    {
        History::create(['preparation_id' => $record->id, 'actor_id' => $user->id, 'event' => $event, 'version' => $record->version, 'details' => $details, 'created_at' => now()]);
    }
}
