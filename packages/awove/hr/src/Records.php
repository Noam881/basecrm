<?php

namespace Awove\Hr;

use Awove\Core\Installation;
use Awove\Core\Models\WorkItem;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Services\WorkItems;
use Awove\Hr\Models\EmploymentCase;
use Awove\Hr\Models\HrDocument;
use Awove\Hr\Models\HrHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Records
{
    public const STAGES = ['new' => 'New', 'screening' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected'];

    public const CATEGORIES = ['documents' => 'Documents', 'access' => 'Access', 'equipment' => 'Equipment', 'training' => 'Introductory training', 'handover' => 'Handover', 'final-pay' => 'Final-pay information', 'other' => 'Other'];

    private static function labels(array $defaults, string $key): array
    {
        $overrides = Installation::manifest()[$key] ?? [];
        if (! is_array($overrides)) {
            throw new \RuntimeException('HR display labels must be a map.');
        }
        foreach (array_intersect_key($overrides, $defaults) as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('HR display labels must be non-empty text of at most 100 characters.');
            }
        }

        return array_replace(array_map(fn ($label) => (string) __($label), $defaults), array_intersect_key($overrides, $defaults));
    }

    public static function stages(): array
    {
        return self::labels(self::STAGES, 'hr_candidate_stages');
    }

    public static function employmentStatuses(): array
    {
        return self::labels(['active' => 'Active', 'inactive' => 'Inactive'], 'hr_employment_statuses');
    }

    public static function vacancyStatuses(): array
    {
        return self::labels(['open' => 'Open', 'closed' => 'Closed'], 'hr_vacancy_statuses');
    }

    public static function checklistStatuses(): array
    {
        return self::labels(['not-started' => 'Not started', 'in-progress' => 'In progress', 'done' => 'Done', 'skipped' => 'Skipped'], 'hr_checklist_statuses');
    }

    public function save($user, string $section, array $input, ?int $id = null): Model
    {
        return DB::transaction(function () use ($user, $section, $input, $id) {
            $user = Access::fresh($user);
            $prefix = Access::prefix($section);
            $record = null;
            if ($id) {
                $record = Access::query($user, $section)->lockForUpdate()->findOrFail($id);
                Access::edit($user, $record);
                $this->version($record, $input);
            } else {
                abort_unless(Access::available($user, $section) && $user->can($prefix.'.create'), 403);
            }
            $values = [...($record?->getAttributes() ?? ['owner_id' => $user->id]), ...$input];
            $common = ['owner_id' => 'required|integer|min:1'];
            $data = match ($section) {
                'employees' => $this->employment($user, $values, $record, $common),
                'vacancies' => validator($values, [...$common, 'role_name' => 'required|string|max:180', 'description' => 'required|string|max:20000', 'opened_on' => 'required|date_format:Y-m-d', 'status' => ['required', Rule::in(['open', 'closed'])]])->validate(),
                'candidates' => $this->candidate($user, $values, $record, $common, $input),
                'cases' => $this->employmentCase($user, $values, $record, $common, $input), default => abort(404),
            };
            foreach (['name', 'role_name', 'description', 'responsibilities'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = trim($data[$field]);
                    if ($data[$field] === '') {
                        throw ValidationException::withMessages([$field => __('Enter a value.')]);
                    }
                }
            }
            $data['owner_id'] = (int) $data['owner_id'];
            $this->owner($user, $section, $data['owner_id'], $record);
            if ($record) {
                $record->fill($data);
                $fields = array_keys($record->getDirty());
                $record->version++;
                $record->save();
            } else {
                $model = Access::MODELS[$section];
                $record = $model::create([...$data, 'created_by' => $user->id, 'version' => 1]);
                $fields = array_keys($data);
            }
            $this->history($user, $record, $id ? 'updated' : 'created', $fields);

            return $record;
        });
    }

    private function employment($u, array $values, ?Model $record, array $common): array
    {
        $data = validator($values, [...$common, 'employee_reference_id' => ['required', 'integer', Rule::unique('hr_employment_profiles')->ignore($record?->getKey())], 'email' => 'nullable|email|max:254', 'phone' => 'nullable|string|max:80', 'role_name' => 'required|string|max:180', 'role_description' => 'nullable|string|max:20000', 'responsibilities' => 'required|string|max:20000', 'manager_employee_reference_id' => 'nullable|integer', 'start_on' => 'required|date_format:Y-m-d', 'employment_status' => ['required', Rule::in(['active', 'inactive'])]])->validate();
        $this->employee($u, $data, 'employee_reference_id', $record);
        if ($record && (int) $data['employee_reference_id'] !== $record->getAttribute('employee_reference_id')) {
            throw ValidationException::withMessages(['employee_reference_id' => __('An employment profile retains its employee identity. Create a separate profile for another employee.')]);
        }
        $this->employee($u, $data, 'manager_employee_reference_id', $record);
        if (isset($data['manager_employee_reference_id']) && (int) $data['manager_employee_reference_id'] === (int) $data['employee_reference_id']) {
            throw ValidationException::withMessages(['manager_employee_reference_id' => __('Choose another employee as manager.')]);
        }

        return $data;
    }

    private function candidate($u, array $values, ?Model $record, array $common, array $input): array
    {
        foreach (['vacancy_id', 'employee_reference_id'] as $field) {
            if (($input[$field.'_action'] ?? null) === 'keep' && $record) {
                $values[$field] = $record->getAttribute($field);
            }
        }
        $data = validator($values, [...$common, 'vacancy_id' => 'required|integer', 'name' => 'required|string|max:180', 'email' => 'nullable|required_without:phone|email|max:254', 'phone' => 'nullable|required_without:email|string|max:80', 'notes' => 'nullable|string|max:20000', 'stage' => ['required', Rule::in(array_keys(self::STAGES))], 'next_action_on' => 'nullable|date_format:Y-m-d', 'employee_reference_id' => 'nullable|integer'])->validate();
        if (! $record || (int) $data['vacancy_id'] !== $record->getAttribute('vacancy_id')) {
            $vacancy = Access::query($u, 'vacancies')->whereNull('archived_at')->where('status', 'open')->find($data['vacancy_id']);
            if (! $vacancy) {
                throw ValidationException::withMessages(['vacancy_id' => __('Choose an accessible open vacancy.')]);
            }
        }
        if (isset($data['employee_reference_id']) && (! $record || (int) $data['employee_reference_id'] !== $record->getAttribute('employee_reference_id')) && $data['stage'] !== 'hired') {
            throw ValidationException::withMessages(['employee_reference_id' => __('Resolve an employee explicitly when the candidate is hired.')]);
        }
        $this->employee($u, $data, 'employee_reference_id', $record);

        return $data;
    }

    private function employmentCase($u, array $values, ?Model $record, array $common, array $input): array
    {
        if (array_key_exists('final_pay_info', $input)) {
            abort_unless($u->can('hr.final-pay.view') && $u->can('hr.final-pay.edit'), 403);
        }
        $data = validator($values, [...$common, 'employee_reference_id' => 'required|integer', 'kind' => ['required', Rule::in(['onboarding', 'offboarding'])], 'name' => 'required|string|max:180', 'due_on' => 'required|date_format:Y-m-d', 'final_work_on' => ($values['kind'] ?? '') === 'offboarding' ? 'required|date_format:Y-m-d' : 'nullable|prohibited', 'final_pay_info' => ($values['kind'] ?? '') === 'offboarding' ? 'nullable|string|max:20000' : 'nullable|prohibited'])->validate();
        $this->employee($u, $data, 'employee_reference_id', $record, $data['kind'] === 'offboarding');
        if ($record && ((int) $data['employee_reference_id'] !== $record->getAttribute('employee_reference_id') || $data['kind'] !== $record->getAttribute('kind'))) {
            throw ValidationException::withMessages(['employee_reference_id' => __('The case retains its employee and onboarding/offboarding purpose.')]);
        }

        return $data;
    }

    private function employee($u, array &$data, string $field, ?Model $record, bool $inactive = false): void
    {
        if (! isset($data[$field])) {
            if ($record && $record->getAttribute($field) !== null) {
                abort_unless(app(EmployeeReferences::class)->available($u), 403);
            } $data[$field] = null;

            return;
        }
        $data[$field] = (int) $data[$field];
        if (! $record || $data[$field] !== $record->getAttribute($field)) {
            app(EmployeeReferences::class)->assertSelectable($u, $data[$field], $inactive);
        }
    }

    private function owner($u, string $section, int $id, ?Model $record): void
    {
        if ($record && $record->getAttribute('owner_id') === $id) {
            return;
        }
        abort_unless($id === $u->id || $u->can(Access::prefix($section).'.assign'), 403);
        $owner = config('auth.providers.users.model')::find($id);
        if (! Access::available($owner, $section)) {
            throw ValidationException::withMessages(['owner_id' => __('Choose an active responsible staff member who already has the appropriate HR access.')]);
        }
    }

    public function version(Model $record, array $input): void
    {
        $data = validator($input, ['version' => 'required|integer|min:1'])->validate();
        abort_unless((int) $data['version'] === $record->getAttribute('version'), 409, __('This HR record changed. Reload before saving.'));
    }

    public function history($u, Model $record, string $event, array $fields = [], array $details = []): void
    {
        $field = match (Access::section($record)) {
            'employees' => 'employment_id','vacancies' => 'vacancy_id','candidates' => 'candidate_id','cases' => 'case_id', default => abort(404)
        };
        HrHistory::create([$field => $record->getKey(), 'actor_id' => $u->id, 'event' => $event, 'changed_fields' => $fields, 'details' => $details]);
    }

    public function archive($u, string $section, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $section, $id, $input) {
            $u = Access::fresh($u);
            $record = Access::query($u, $section)->lockForUpdate()->findOrFail($id);
            Access::view($u, $record);
            $p = Access::prefix($section);
            abort_unless(! $record->getAttribute('archived_at') && ($u->can($p.'.edit.all') || ($record->getAttribute('owner_id') === $u->id && $u->can($p.'.edit.own'))) && $u->can($p.'.archive'), 403);
            $this->version($record, $input);
            if ($record instanceof EmploymentCase) {
                $service = app(WorkItems::class);
                $items = WorkItem::whereIn('id', DB::table('hr_case_items')->where('case_id', $id)->pluck('work_item_id'))->lockForUpdate()->get();
                foreach ($items as $item) {
                    if (! $item->archived_at) {
                        if (! $service->editable($u, $item)) {
                            throw ValidationException::withMessages(['case' => __('An authorized HR manager must archive the remaining assigned checklist work. No work was marked done.')]);
                        }
                        $service->archive($u, 'hr', 'checklist', $item->id, ['version' => $item->version]);
                    }
                }
            }
            $record->update(['archived_at' => now(), 'version' => $record->getAttribute('version') + 1]);
            $this->history($u, $record, 'archived', ['archived_at']);
        });
    }

    public function checklist($u, int $caseId, array $input, ?int $workId = null): WorkItem
    {
        return DB::transaction(function () use ($u, $caseId, $input, $workId) {
            $u = Access::fresh($u);
            $service = app(WorkItems::class);
            if ($workId) {
                $service->find($u, 'hr', 'checklist', $workId);
            }
            $case = EmploymentCase::lockForUpdate()->findOrFail($caseId);
            abort_if($case->archived_at || $case->completed_on, 403, __('This HR case is read only.'));
            if (! $workId) {
                Access::edit($u, $case);
            }
            $link = $workId ? DB::table('hr_case_items')->where('case_id', $caseId)->where('work_item_id', $workId)->first() : null;
            if ($workId) {
                abort_unless($link !== null, 404);
            }
            $data = validator($input, ['category' => ['required', Rule::in(array_keys(self::CATEGORIES))], 'required' => 'required|boolean'])->validate();
            $base = array_intersect_key($input, array_flip([...WorkItems::FIELDS, 'version']));
            if ($data['required'] && ($base['status'] ?? '') === 'skipped') {
                throw ValidationException::withMessages(['status' => __('Required work must be completed; it cannot be skipped.')]);
            }
            $changed = $link && ($link->category !== $data['category'] || $link->required !== (bool) $data['required']) ? ['category', 'required'] : [];
            if ($changed) {
                Access::edit($u, $case);
            }
            $record = $workId ? $service->update($u, 'hr', 'checklist', $workId, $base, $changed) : $service->create($u, 'hr', 'checklist', $base, ['case_id', 'category', 'required']);
            if ($link) {
                DB::table('hr_case_items')->where('id', $link->id)->update([...$data, 'updated_at' => now()]);
            } else {
                DB::table('hr_case_items')->insert([...$data, 'case_id' => $caseId, 'work_item_id' => $record->getKey(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $case->update(['version' => $case->version + 1]);
            $this->history($u, $case, $workId ? 'checklist.updated' : 'checklist.created', ['checklist']);

            return $record;
        });
    }

    public function completeCase($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $case = Access::query($u, 'cases')->lockForUpdate()->findOrFail($id);
            Access::edit($u, $case);
            $this->version($case, $input);
            $data = validator($input, ['completed_on' => 'required|date_format:Y-m-d', 'deactivate_employee' => 'sometimes|boolean', 'employee_version' => 'nullable|integer|min:1'])->validate();
            $items = DB::table('hr_case_items')->join('crm_work_items', 'crm_work_items.id', '=', 'hr_case_items.work_item_id')->where('case_id', $id)->lockForUpdate()->get(['crm_work_items.status', 'hr_case_items.required']);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['case' => __('Add at least one checklist item before completing this case.')]);
            }
            if ($items->contains(fn ($item) => $item->required ? $item->status !== 'done' : ! in_array($item->status, ['done', 'skipped'], true))) {
                throw ValidationException::withMessages(['case' => __('Complete every required checklist item and complete or explicitly skip optional items before completing this case.')]);
            }
            if ($data['deactivate_employee'] ?? false) {
                if ($case->kind !== 'offboarding') {
                    throw ValidationException::withMessages(['deactivate_employee' => __('Only offboarding can explicitly retain an employee as inactive.')]);
                }
                app(EmployeeReferences::class)->setActive($u, $case->employee_reference_id, (int) ($data['employee_version'] ?? 0), false);
            }
            $case->update(['completed_on' => $data['completed_on'], 'version' => $case->version + 1]);
            $this->history($u, $case, 'case.completed', ['completed_on']);
        });
    }

    public function addDocument($u, string $section, int $id, array $input): HrDocument
    {
        return DB::transaction(function () use ($u, $section, $id, $input) {
            $u = Access::fresh($u);
            $record = Access::query($u, $section)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $record);
            abort_unless($u->can('hr.documents.view') && $u->can('hr.documents.manage'), 403);
            abort_if($section === 'vacancies', 404);
            $data = validator($input, ['name' => 'required|string|max:180', 'url' => 'required|string|max:2048'])->validate();
            $data['url'] = app(DocumentLinks::class)->validate($data['url'], 'url');
            $parent = match ($section) {
                'employees' => 'employment_id','candidates' => 'candidate_id','cases' => 'case_id', default => abort(404)
            };
            $doc = HrDocument::create([...$data, $parent => $id, 'created_by' => $u->id]);
            $this->history($u, $record, 'document.added', ['documents']);

            return $doc;
        });
    }
}
