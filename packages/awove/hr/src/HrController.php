<?php

namespace Awove\Hr;

use Awove\Core\Installation;
use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Services\WorkItems;
use Awove\Core\Tables\Query;
use Awove\Hr\Models\EmploymentCase;
use Awove\Hr\Models\HrDocument;
use Awove\Hr\Models\HrHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class HrController
{
    private function sections($u): array
    {
        return array_values(array_filter(array_keys(Access::MODELS), fn ($s) => Access::available($u, $s)));
    }

    private function filters(Request $r): string
    {
        $sections = $this->sections($r->user());
        abort_unless($sections !== [], 403);
        $viewOptions = in_array($r->input('section', $sections[0]), ['candidates', 'cases'], true) ? ['open', 'overdue', 'archived'] : ['open', 'archived'];
        $r->validate(['section' => ['nullable', Rule::in($sections)], 'owner_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in($viewOptions)], 'kind' => ['nullable', Rule::in(['onboarding', 'offboarding'])]]);

        return $r->input('section', $sections[0]);
    }

    private function query(Request $r, string $s)
    {
        $q = Access::query($r->user(), $s);
        $r->input('view') === 'archived' ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        if ($r->filled('owner_id')) {
            $q->where('owner_id', $r->integer('owner_id'));
        }
        $date = match ($s) {
            'employees' => 'start_on','vacancies' => 'opened_on','candidates' => 'next_action_on','cases' => 'due_on', default => abort(404)
        };
        if ($r->filled('from')) {
            $q->where($date, '>=', $r->input('from'));
        }if ($r->filled('to')) {
            $q->where($date, '<=', $r->input('to'));
        }
        if ($s === 'cases' && $r->filled('kind')) {
            $q->where('kind', $r->input('kind'));
        }
        if (in_array($r->input('view'), ['open', 'overdue'], true)) {
            match ($s) {
                'employees' => $q->where('employment_status', 'active'),'vacancies' => $q->where('status', 'open'),'candidates' => $q->whereNotIn('stage', ['hired', 'rejected']),'cases' => $q->whereNull('completed_on')
            };
            if ($r->input('view') === 'overdue' && in_array($s, ['candidates', 'cases'], true)) {
                $q->where($date, '<', now(Installation::settings()['timezone'])->toDateString());
            }
        }

        return $q;
    }

    private function person(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id, ['id', 'name'])?->only(['id', 'name']) : null;
    }

    private function options($u, string $s, ?Model $record = null): array
    {
        $u = Access::fresh($u);
        $owners = Access::owners($u, $s);
        $owner = $record ? $this->person($record->getAttribute('owner_id')) : null;
        if ($owner && ! in_array($owner['id'], array_column($owners, 'id'), true)) {
            $owners[] = $owner;
        }
        $ownerIds = Access::query($u, $s)->distinct()->pluck('owner_id');
        $filterOwners = config('auth.providers.users.model')::whereIn('id', $ownerIds)->orderBy('name')->get(['id', 'name']);

        return ['sections' => $this->sections($u), 'owners' => $owners, 'filterOwners' => $filterOwners, 'employees' => app(EmployeeReferences::class)->options($u), 'retainedEmployees' => $s === 'cases' ? app(EmployeeReferences::class)->options($u, true) : [], 'vacancies' => Access::query($u, 'vacancies')->whereNull('archived_at')->where('status', 'open')->get(['id', 'role_name']), 'stages' => Records::stages(), 'employmentStatuses' => Records::employmentStatuses(), 'vacancyStatuses' => Records::vacancyStatuses(), 'checklistStatuses' => Records::checklistStatuses(), 'categories' => Records::CATEGORIES, 'today' => now(Installation::settings()['timezone'])->toDateString(), 'canCreate' => $u->can(Access::prefix($s).'.create'), 'canAssign' => $u->can(Access::prefix($s).'.assign'), 'canArchive' => $u->can(Access::prefix($s).'.archive'), 'canExport' => $u->can(Access::prefix($s).'.export'), 'canViewDocuments' => $u->can('hr.documents.view'), 'canManageDocuments' => $u->can('hr.documents.view') && $u->can('hr.documents.manage'), 'canViewFinalPay' => $u->can('hr.final-pay.view'), 'canEditFinalPay' => $u->can('hr.final-pay.view') && $u->can('hr.final-pay.edit'), 'canManageEmployees' => $u->can('employees.references.manage') && $u->can('employees.references.view')];
    }

    private function serialize($u, Model $record): array
    {
        $u = Access::fresh($u);
        $data = $record->toArray();
        $s = Access::section($record);
        $data['owner'] = $this->person($record->getAttribute('owner_id'));
        unset($data['final_pay_info']);
        if ($s === 'cases' && $u->can('hr.final-pay.view')) {
            $data['final_pay_info'] = $record->getAttribute('final_pay_info');
        }
        foreach (['employee_reference_id' => 'employee', 'manager_employee_reference_id' => 'manager'] as $field => $name) {
            if (array_key_exists($field, $data)) {
                $id = $record->getAttribute($field);
                $summary = app(EmployeeReferences::class)->summary($u, $id);
                $data[$name] = $summary;
                $data[$field] = $summary['id'] ?? null;
                $data[$name.'_restricted'] = $id !== null && $summary === null;
            }
        }
        if ($s === 'candidates') {
            $vacancy = Access::query($u, 'vacancies')->find($record->getAttribute('vacancy_id'), ['id', 'role_name']);
            $data['vacancy'] = $vacancy?->only(['id', 'role_name']);
            $data['vacancy_id'] = $vacancy?->id;
            $data['vacancy_restricted'] = $vacancy === null;
        }

        return $data;
    }

    private function history($u, Model $record): array
    {
        $u = Access::fresh($u);
        $parent = match (Access::section($record)) {
            'employees' => 'employment_id','vacancies' => 'vacancy_id','candidates' => 'candidate_id','cases' => 'case_id', default => abort(404)
        };

        return HrHistory::where($parent, $record->getKey())->orderByDesc('id')->get()->map(fn ($event) => ['id' => $event->id, 'event' => $event->event, 'changed_fields' => array_values(array_filter($event->changed_fields, fn ($field) => $field !== 'final_pay_info' || $u->can('hr.final-pay.view'))), 'created_at' => $event->getAttribute('created_at')->toISOString(), 'actor' => $this->person($event->actor_id)])->all();
    }

    public function index(Request $r)
    {
        $s = $this->filters($r);
        $metrics = [];
        foreach ($this->sections($r->user()) as $section) {
            $q = $this->query($r, $section);
            $metrics[$section] = match ($section) {
                'employees' => $q->where('employment_status', 'active')->whereExists(fn ($q) => $q->selectRaw('1')->from('crm_employee_references')->whereColumn('crm_employee_references.id', 'hr_employment_profiles.employee_reference_id')->where('active', true))->count(),'vacancies' => $q->where('status', 'open')->count(),'candidates' => $q->whereNotIn('stage', ['hired', 'rejected'])->count(),'cases' => $q->whereNull('completed_on')->count(), default => abort(404)
            };
        }
        $table = app(Query::class)->run($this->query($r, $s), 'hr.'.$s, $r);
        $table['page']->through(fn ($record) => [...$this->serialize($r->user(), $record), 'record_url' => '/hr/'.$s.'/'.$record->getKey(), 'record_link_column' => $s === 'employees' && Access::fresh($r->user())->can('employees.references.view') ? 'employee' : (in_array($s, ['employees', 'vacancies'], true) ? 'role_name' : 'name')]);

        return Inertia::render('Hr/Index', [...$this->options($r->user(), $s), 'section' => $s, 'metrics' => $metrics, 'table' => $table, 'filters' => $r->only(['owner_id', 'from', 'to', 'view', 'kind'])]);
    }

    public function store(Request $r, string $section, Records $service)
    {
        $record = $service->save($r->user(), $section, $r->all());

        return redirect('/hr/'.$section.'/'.$record->getKey());
    }

    public function update(Request $r, string $section, int $id, Records $service)
    {
        $service->save($r->user(), $section, $r->all(), $id);

        return back();
    }

    public function archive(Request $r, string $section, int $id, Records $service)
    {
        $service->archive($r->user(), $section, $id, $r->all());

        return redirect('/hr?section='.$section);
    }

    public function show(Request $r, string $section, int $id)
    {
        $u = Access::fresh($r->user());
        $record = Access::query($u, $section)->findOrFail($id);
        $parent = match ($section) {
            'employees' => 'employment_id','vacancies' => null,'candidates' => 'candidate_id','cases' => 'case_id', default => abort(404)
        };
        $documents = $parent && $u->can('hr.documents.view') ? HrDocument::where($parent, $id)->orderByDesc('id')->get()->map(fn ($doc) => ['id' => $doc->id, 'name' => $doc->name, 'archived_at' => $doc->archived_at, 'version' => $doc->version, 'href' => '/hr/documents/'.$doc->id])->all() : [];
        $items = [];
        $employeeVersion = null;
        if ($section === 'cases') {
            $service = app(WorkItems::class);
            $links = DB::table('hr_case_items')->where('case_id', $id)->get()->keyBy('work_item_id');
            $items = $service->query($u, 'hr', 'checklist')->whereIn('id', $links->keys())->orderBy('id')->get()->map(fn ($item) => [...$item->toArray(), 'category' => $links[$item->id]->category, 'required' => $links[$item->id]->required, 'editable' => $service->editable($u, $item) && Access::editable($u, $record), 'href' => '/hr/checklist/'.$item->id])->all();
            if ($u->can('employees.references.view') && $u->can('employees.references.manage')) {
                $employeeVersion = app(EmployeeReferences::class)->find($u, $record->getAttribute('employee_reference_id'))->getAttribute('version');
            }
        }

        return Inertia::render('Hr/Record', [...$this->options($u, $section, $record), 'section' => $section, 'record' => $this->serialize($u, $record), 'editable' => Access::editable($u, $record), 'archivable' => $record->getAttribute('archived_at') === null && $u->can(Access::prefix($section).'.archive') && ($u->can(Access::prefix($section).'.edit.all') || ($record->getAttribute('owner_id') === $u->id && $u->can(Access::prefix($section).'.edit.own'))), 'history' => $this->history($u, $record), 'documents' => $documents, 'items' => $items, 'employeeVersion' => $employeeVersion]);
    }

    public function addDocument(Request $r, string $section, int $id, Records $service)
    {
        $service->addDocument($r->user(), $section, $id, $r->all());

        return back();
    }

    private function documentParent(HrDocument $doc): array
    {
        foreach (['employment_id' => 'employees', 'candidate_id' => 'candidates', 'case_id' => 'cases'] as $field => $s) {
            if ($doc->getAttribute($field)) {
                return [$s, $doc->getAttribute($field)];
            }
        }abort(404);
    }

    public function document(Request $r, int $id, DocumentLinks $links)
    {
        $u = Access::fresh($r->user());
        abort_unless($u->can('hr.documents.view'), 403);
        $doc = HrDocument::findOrFail($id);
        [$s,$parent] = $this->documentParent($doc);
        Access::query($r->user(), $s)->findOrFail($parent);

        return $links->redirect($doc->url);
    }

    public function archiveDocument(Request $r, int $id, Records $service)
    {
        DB::transaction(function () use ($r, $id, $service) {
            $u = Access::fresh($r->user());
            abort_unless($u->can('hr.documents.view') && $u->can('hr.documents.manage'), 403);
            $doc = HrDocument::lockForUpdate()->findOrFail($id);
            [$s,$parent] = $this->documentParent($doc);
            $record = Access::query($u, $s)->lockForUpdate()->findOrFail($parent);
            Access::edit($u, $record);
            $service->version($doc, $r->all());
            abort_if($doc->archived_at !== null, 403);
            $doc->update(['archived_at' => now(), 'version' => $doc->version + 1]);
            $service->history($u, $record, 'document.archived', ['documents']);
        });

        return back();
    }

    public function storeChecklist(Request $r, int $id, Records $service)
    {
        $item = $service->checklist($r->user(), $id, $r->all());

        return redirect('/hr/checklist/'.$item->id);
    }

    public function updateChecklist(Request $r, int $id, Records $service, WorkItems $work)
    {
        $work->find($r->user(), 'hr', 'checklist', $id);
        $link = DB::table('hr_case_items')->where('work_item_id', $id)->firstOrFail();
        $service->checklist($r->user(), $link->case_id, $r->all(), $id);

        return back();
    }

    public function checklist(Request $r, int $id, WorkItems $service)
    {
        $u = Access::fresh($r->user());
        $record = $service->find($u, 'hr', 'checklist', $id);
        $link = DB::table('hr_case_items')->where('work_item_id', $id)->firstOrFail();
        $case = Access::query($u, 'cases')->find($link->case_id);
        $state = EmploymentCase::whereKey($link->case_id)->firstOrFail(['completed_on', 'archived_at']);

        return Inertia::render('Hr/Checklist', [...$this->options($u, 'cases'), 'record' => [...$record->toArray(), 'category' => $link->category, 'required' => $link->required], 'parentCase' => $case ? ['id' => $case->id, 'name' => $case->name] : null, 'canEditMetadata' => $case && Access::editable($u, $case), 'editable' => ! $state->completed_on && ! $state->archived_at && $service->editable($u, $record), 'history' => $service->history($u, $record)]);
    }

    public function completeCase(Request $r, int $id, Records $service)
    {
        $service->completeCase($r->user(), $id, $r->all());

        return back();
    }

    public function export(Request $r, Csv $csv)
    {
        $s = $this->filters($r);
        abort_unless(Access::fresh($r->user())->can(Access::prefix($s).'.export'), 403);
        $q = app(Query::class)->filtered($this->query($r, $s), 'hr.'.$s, $r);
        abort_if((clone $q)->count() > 5000, 422, 'Narrow the filters to export at most 5,000 HR records.');
        $headers = match ($s) {
            'employees' => ['id', 'role_name', 'email', 'phone', 'start_on', 'employment_status', 'owner_id'],'vacancies' => ['id', 'role_name', 'description', 'opened_on', 'status', 'owner_id'],'candidates' => ['id', 'name', 'email', 'phone', 'stage', 'next_action_on', 'owner_id'],'cases' => ['id', 'name', 'kind', 'due_on', 'completed_on', 'final_work_on', 'owner_id'], default => abort(404)
        };
        $rows = (function () use ($q, $headers) {
            foreach ($q->orderBy('id')->cursor() as $record) {
                yield array_map(fn ($field) => $record->toArray()[$field] ?? '', $headers);
            }
        })();

        return $csv->download('hr-'.$s.'.csv', $headers, $rows);
    }
}
