<?php

namespace Awove\Core\Http;

use Awove\Core\Access;
use Awove\Core\ContactDetails;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Reminder;
use Awove\Core\Models\Status;
use Awove\Core\Models\Task;
use Awove\Core\Modules;
use Awove\Core\Services\Identities;
use Awove\Core\Services\IdentityHistory;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Proposals;
use Awove\Core\Services\Sales;
use Awove\Core\Services\Tasks;
use Awove\Core\Services\Users;
use Awove\Core\Services\WorkReminders;
use Awove\Core\Tables\Preferences;
use Awove\Core\Tables\Query;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CrmController
{
    private function options($u): array
    {
        return ['statuses' => Status::orderBy('position')->get(), 'contacts' => Access::identities($u, Contact::class)->orderBy('name')->limit(200)->get(['id', 'name']), 'companies' => Access::identities($u, Company::class)->orderBy('name')->limit(200)->get(['id', 'name']), 'owners' => $u->can('leads.assign') ? config('auth.providers.users.model')::where('active', true)->get(['id', 'name']) : [['id' => $u->id, 'name' => $u->name]], 'fields' => Installation::manifest()['custom_fields']];
    }

    public function dashboard(Request $r)
    {
        if (! app(Modules::class)->enabled('sales')) {
            return Inertia::render('Core/Workspace', ['moduleNavigation' => app(Modules::class)->navigation($r->user()), 'moduleWidgets' => app(Modules::class)->dashboardWidgets($r->user())]);
        }
        $filters = $r->validate(['owner' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($r->filled('from') ? '|after_or_equal:from' : '')]);
        $q = Access::leads($r->user())->whereNull('archived_at');
        if ($r->filled('owner')) {
            $q->where('owner_id', $r->integer('owner'));
        }
        $won = (clone $q)->whereHas('status', fn ($q) => $q->where('kind', 'won'));
        // Undated won records cannot be assigned to a closing period; report their presence independently.
        $wonMissingClosingDate = (clone $won)->whereNull('closing_date')->count();
        // Pipeline dates describe opportunity creation; won dates describe the actual closing date.
        $zone = Installation::display($r->user())['timezone'];
        if ($r->filled('from')) {
            $q->where('created_at', '>=', CarbonImmutable::parse($r->input('from'), $zone)->startOfDay()->utc());
            $won->where('closing_date', '>=', $r->input('from'));
        }
        if ($r->filled('to')) {
            $q->where('created_at', '<=', CarbonImmutable::parse($r->input('to'), $zone)->endOfDay()->utc());
            $won->where('closing_date', '<=', $r->input('to'));
        }
        $tasks = Access::tasks($r->user())->where('status', 'open')->whereIn('lead_id', (clone $q)->select('id'));
        $day = now($zone);
        $pipeline = (clone $q)->whereHas('status', fn ($q) => $q->where('kind', 'active'));
        $pipelineValue = (clone $pipeline)->selectRaw('SUM(estimated_value)::text AS total')->value('total');
        $wonValue = (clone $won)->selectRaw('SUM(final_value)::text AS total')->value('total');

        return Inertia::render('Core/Dashboard', [
            'moduleWidgets' => app(Modules::class)->dashboardWidgets($r->user()),
            'filters' => $filters,
            'owners' => $this->options($r->user())['owners'],
            'salesOverview' => [
                'estimated_pipeline' => $pipelineValue,
                'won_count' => (clone $won)->count(),
                'won_value' => $wonValue,
                'won_missing_value' => (clone $won)->whereNull('final_value')->count(),
                'won_missing_closing_date' => $wonMissingClosingDate,
            ],
            'metrics' => [
                'active' => (clone $pipeline)->count(),
                'new' => (clone $q)->where('status_id', 'new')->count(),
                'missing' => (clone $pipeline)->doesntHave('tasks', 'and', fn ($q) => $q->where('status', 'open'))->count(),
                'unassigned' => $r->user()->can('leads.view.all') ? (clone $q)->whereNull('owner_id')->count() : null,
                'due' => (clone $tasks)->whereBetween('due_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])->count(),
                'overdue' => (clone $tasks)->where('due_at', '<', now())->count(),
            ],
            'stages' => Status::orderBy('position')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'count' => (clone $q)->where('status_id', $s->id)->count()]),
            'tasks' => $tasks->with('lead:id,title')->orderBy('due_at')->limit(8)->get(),
        ]);
    }

    public function index(Request $r)
    {
        $r->validate(['owner' => 'nullable|integer', 'status' => 'nullable|string|max:50', 'source' => 'nullable|string|max:100', 'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])], 'view' => ['nullable', Rule::in(['missing', 'unassigned', 'archived'])]]);
        $q = Access::leads($r->user())->with('status', 'owner:id,name', 'contact:id,name', 'company:id,name');
        $r->view === 'archived' ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        foreach (['owner' => 'owner_id', 'status' => 'status_id', 'source' => 'source', 'priority' => 'priority'] as $input => $column) {
            if ($r->filled($input)) {
                $q->where($column, $r->input($input));
            }
        }
        if ($r->view === 'missing') {
            $q->whereDoesntHave('tasks', fn ($q) => $q->where('status', 'open'));
        }
        if ($r->view === 'unassigned') {
            $r->user()->can('leads.view.all') ?: abort(403);
            $q->whereNull('owner_id');
        }

        $table = app(Query::class)->run($q, 'leads', $r);
        $table['page']->getCollection()->each(fn (Lead $lead) => $lead->setAttribute('editable', Gate::forUser($r->user())->allows('update', $lead)));

        return Inertia::render('Core/Leads', [...$this->options($r->user()), 'table' => $table, 'leads' => $table['page'], 'filters' => $r->only('owner', 'status', 'source', 'priority', 'view'), 'preferences' => $table['preferences']]);
    }

    private function leadData(Request $r, bool $update = false): array
    {
        $data = $r->validate(['title' => 'sometimes|nullable|string|max:160', 'contact_id' => 'nullable|integer', 'company_id' => 'nullable|integer', 'owner_id' => 'nullable|integer', 'status_id' => ($update ? 'sometimes' : 'required').'|exists:crm_statuses,id', 'source' => 'sometimes|required|string|max:100', 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])], 'custom_fields' => 'sometimes|array', 'version' => $update ? 'required|integer' : 'prohibited', 'attribution' => 'missing', ...Sales::opportunityRules()]);
        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $key => $model) {
            if (! empty($data[$key])) {
                abort_unless(Access::identities($r->user(), $model)->whereKey($data[$key])->exists(), 403);
            }
        }
        if (! $update && ! $r->user()->can('leads.assign')) {
            $data['owner_id'] = $r->user()->id;
        }
        if (isset($data['owner_id'])) {
            abort_unless($data['owner_id'] == $r->user()->id || $r->user()->can('leads.assign'), 403);
            abort_unless(config('auth.providers.users.model')::where('active', true)->whereKey($data['owner_id'])->exists(), 422);
        }
        if (! $update) {
            abort_unless(! empty($data['contact_id']) || ! empty($data['company_id']), 422, 'Choose a contact or company.');
        }

        return $data;
    }

    public function store(Request $r, Leads $service)
    {
        $lead = $service->create($r->user(), $this->leadData($r));

        return redirect('/leads/'.$lead->id);
    }

    public function show(Request $r, Lead $lead)
    {
        Gate::authorize('view', $lead);

        return Inertia::render('Core/Lead', [...$this->options($r->user()), 'lead' => $lead->load('contact', 'company', 'status', 'owner:id,name', 'notes.author:id,name', 'tasks.assignee:id,name', 'activity.actor:id,name'), 'editable' => Gate::allows('update', $lead), 'identityPermissions' => ['contact' => $lead->contact && Gate::allows('update', $lead->contact), 'company' => $lead->company && Gate::allows('update', $lead->company)], 'proposals' => app(Proposals::class)->forLead($r->user(), $lead), 'sales' => app(Sales::class)->details($r->user(), $lead), 'panels' => app(Modules::class)->panels($r->user())]);
    }

    public function update(Request $r, Lead $lead, Leads $service)
    {
        $service->update($r->user(), $lead->id, $this->leadData($r, true));

        return back();
    }

    public function archive(Request $r, Lead $lead, Leads $service)
    {
        Gate::authorize('archive', $lead);
        DB::transaction(function () use ($lead, $r, $service) {
            $locked = Lead::lockForUpdate()->findOrFail($lead->id);
            Gate::authorize('archive', $locked);
            $locked->update(['archived_at' => now(), 'version' => $locked->version + 1]);
            $service->log($locked, $r->user(), 'lead.archived', []);
        });

        return back();
    }

    public function note(Request $r, Lead $lead, Leads $service)
    {
        Gate::authorize('update', $lead);
        $data = $r->validate(['body' => 'required|string|max:10000']);
        DB::transaction(function () use ($r, $lead, $service, $data) {
            $lead->notes()->create([...$data, 'author_id' => $r->user()->id]);
            $service->log($lead, $r->user(), 'note.created', []);
        });

        return back();
    }

    private function taskData(Request $r): array
    {
        $data = $r->validate(['title' => 'required|string|max:160', 'assignee_id' => 'required|integer', 'due_at' => 'required|date_format:Y-m-d\TH:i:sP', 'remind_at' => 'required|date_format:Y-m-d\TH:i:sP|before_or_equal:due_at', 'priority' => ['required', Rule::in(['low', 'normal', 'high'])]]);
        foreach (['due_at', 'remind_at'] as $k) {
            $data[$k] = CarbonImmutable::parse($data[$k])->utc();
        }

        return $data;
    }

    public function task(Request $r, Lead $lead)
    {
        app(Tasks::class)->create($r->user(), $lead, $this->taskData($r));

        return back();
    }

    public function updateTask(Request $r, Task $task)
    {
        $data = $r->validate(['version' => 'required|integer', 'status' => ['required', Rule::in(['open', 'completed', 'cancelled'])], 'due_at' => 'sometimes|required|date_format:Y-m-d\TH:i:sP', 'remind_at' => 'sometimes|required|date_format:Y-m-d\TH:i:sP']);
        app(Tasks::class)->update($r->user(), $task, $data);

        return back();
    }

    public function tasks(Request $r)
    {
        $r->validate(['view' => ['nullable', Rule::in(['today', 'overdue', 'completed'])]]);
        $q = Access::tasks($r->user())->with('lead:id,title', 'assignee:id,name')->where('status', $r->view === 'completed' ? 'completed' : 'open');
        if ($r->view === 'overdue') {
            $q->where('due_at', '<', now());
        }if ($r->view === 'today') {
            $day = now(Installation::display($r->user())['timezone']);
            $q->whereBetween('due_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()]);
        }

        $table = app(Query::class)->run($q, 'tasks', $r);

        return Inertia::render('Core/Tasks', ['table' => $table, 'tasks' => $table['page'], 'view' => $r->view]);
    }

    public function people(Request $r)
    {
        Gate::authorize('leads.create');
        $type = $r->query('type', $r->query('table', 'contacts'));
        validator(['type' => $type], ['type' => ['required', Rule::in(['contacts', 'companies'])]])->validate();

        return Inertia::render('Core/People', [
            'recordType' => $type,
            'returnTo' => app(Modules::class)->enabled('sales') ? '/leads' : '/dashboard',
            'companyOptions' => Access::identities($r->user(), Company::class)->orderBy('name')->limit(200)->get(['id', 'name']),
            'contactOptions' => ContactDetails::options(),
        ]);
    }

    public function identity(Request $r, string $type, Identities $service)
    {
        $identity = $service->create($r->user(), $type, $r->all());

        return redirect('/people/'.$type.'/'.$identity->id);
    }

    private function identityReturnTo(Request $r): string
    {
        $r->validate(['from_lead' => 'nullable|integer|min:1']);
        if ($r->filled('from_lead')) {
            abort_unless(Access::leads($r->user())->whereKey($r->integer('from_lead'))->exists(), 403);

            return '/leads/'.$r->integer('from_lead');
        }

        return app(Modules::class)->enabled('sales') ? '/leads' : '/dashboard';
    }

    public function showIdentity(Request $r, string $type, int $id, Identities $service)
    {
        $identity = Access::identities($r->user(), $service->model($type))->findOrFail($id);
        Gate::authorize('view', $identity);
        $companies = Access::identities($r->user(), Company::class)->orderBy('name')->limit(200)->get(['id', 'name']);
        if ($type === 'contacts' && $identity->getAttribute('company_id') && ! $companies->contains('id', $identity->getAttribute('company_id'))) {
            $current = Access::identities($r->user(), Company::class)->find($identity->getAttribute('company_id'), ['id', 'name']);
            $companies->push($current ?? new Company(['id' => $identity->getAttribute('company_id'), 'name' => 'Current company (restricted)']));
        }

        $returnTo = $this->identityReturnTo($r);
        $context = $r->filled('from_lead') ? '?from_lead='.$r->integer('from_lead') : '';
        $details = $identity->only(['id', ...($type === 'contacts' ? ContactDetails::ATTRIBUTES : ['name', 'email', 'phone'])]);
        if ($identity instanceof Contact) {
            $details['company'] = $companies->firstWhere('id', $identity->getAttribute('company_id'))?->only(['id', 'name']);
        }
        $details['notes'] = $identity->notes()->with('author:id,name')->latest('created_at')->orderByDesc('id')->get()->map(fn ($note) => [
            'id' => $note->id,
            'body' => $note->body,
            'created_at' => $note->created_at,
            'author' => $note->author?->only(['id', 'name']),
        ]);

        return Inertia::render('Core/Person', [
            'identity' => $details,
            'identityHistory' => app(IdentityHistory::class)->forIdentity($r->user(), $identity),
            'type' => $type,
            'editable' => Gate::allows('update', $identity),
            'companyOptions' => $companies,
            'contactOptions' => ContactDetails::options(),
            'returnTo' => $returnTo,
            'saveUrl' => '/people/'.$type.'/'.$id.$context,
            'noteUrl' => '/people/'.$type.'/'.$id.'/notes'.$context,
        ]);
    }

    public function updateIdentity(Request $r, string $type, int $id, Identities $service)
    {
        $returnTo = $this->identityReturnTo($r);
        $service->update($r->user(), $type, $id, $r->all());

        return redirect($r->filled('from_lead') ? $returnTo : '/people/'.$type.'/'.$id);
    }

    public function contactNote(Request $r, int $id, Identities $service)
    {
        return $this->identityNote($r, 'contacts', $id, $service);
    }

    public function companyNote(Request $r, int $id, Identities $service)
    {
        return $this->identityNote($r, 'companies', $id, $service);
    }

    private function identityNote(Request $r, string $type, int $id, Identities $service)
    {
        $this->identityReturnTo($r);
        $type === 'contacts' ? $service->note($r->user(), $id, $r->all()) : $service->companyNote($r->user(), $id, $r->all());
        $context = $r->filled('from_lead') ? '?from_lead='.$r->integer('from_lead') : '';

        return redirect('/people/'.$type.'/'.$id.$context);
    }

    private function reminderQuery($u)
    {
        return Reminder::where('user_id', $u->id)->whereHas('task', fn ($q) => $q->whereColumn('version', 'crm_reminders.task_version')->where('status', 'open')->whereIn('lead_id', Access::leads($u)->whereNull('archived_at')->select('id')));
    }

    public function reminders(Request $r)
    {
        return Inertia::render('Core/Reminders', ['reminders' => $this->reminderQuery($r->user())->with('task.lead:id,title')->latest()->paginate(30), 'workReminders' => app(WorkReminders::class)->forUser($r->user())]);
    }

    public function read(Request $r, Reminder $reminder)
    {
        $item = $this->reminderQuery($r->user())->findOrFail($reminder->id);
        $item->update(['read_at' => now()]);

        return back();
    }

    public function preferences(Request $r)
    {
        app(Preferences::class)->save($r);

        return $r->expectsJson() ? response()->json(['saved' => true]) : back();
    }

    public function settings(Request $r)
    {
        Gate::authorize('settings.manage');
        Artisan::call('crm:diagnose');

        return Inertia::render('Core/Settings', ['settings' => Installation::settings(), 'locks' => Installation::locks(), 'statuses' => Status::orderBy('position')->get(), 'users' => $r->user()->can('users.manage') ? config('auth.providers.users.model')::with('roles')->get(['id', 'name', 'email', 'active']) : [], 'system' => json_decode(Artisan::output(), true)]);
    }

    public function saveSettings(Request $r)
    {
        Gate::authorize('settings.manage');
        Installation::save($r->all());

        return back();
    }

    public function status(Request $r)
    {
        Gate::authorize('settings.manage');
        $data = $r->validate(['id' => 'required|exists:crm_statuses,id', 'name' => 'required|string|max:80', 'position' => 'required|integer|min:0|max:100']);
        Status::findOrFail($data['id'])->update($data);

        return back();
    }

    public function user(Request $r)
    {
        Gate::authorize('users.manage');
        $data = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|unique:users', 'role' => ['required', Rule::in(['Administrator', 'Employee'])]]);
        app(Users::class)->invite($r->user(), $data);

        return back();
    }

    public function deactivate(Request $r, int $id)
    {
        Gate::authorize('users.manage');
        $data = $r->validate(['active' => 'required|boolean', 'reassign_to' => 'nullable|integer']);
        app(Users::class)->deactivate($r->user(), $id, $data);

        return back();
    }
}
