<?php

namespace Awove\Core\Http;

use Awove\Core\Access;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Reminder;
use Awove\Core\Models\Status;
use Awove\Core\Models\Task;
use Awove\Core\Modules;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Tasks;
use Awove\Core\Services\Users;
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
        $q = Access::leads($r->user())->whereNull('archived_at');
        $tasks = Access::tasks($r->user())->where('status', 'open');
        $day = now(Installation::display($r->user())['timezone']);

        return Inertia::render('Core/Dashboard', ['metrics' => ['active' => (clone $q)->whereHas('status', fn ($q) => $q->where('kind', 'active'))->count(), 'new' => (clone $q)->where('status_id', 'new')->count(), 'missing' => (clone $q)->doesntHave('tasks', 'and', fn ($q) => $q->where('status', 'open'))->count(), 'unassigned' => $r->user()->can('leads.view.all') ? (clone $q)->whereNull('owner_id')->count() : null, 'due' => (clone $tasks)->whereBetween('due_at', [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc()])->count(), 'overdue' => (clone $tasks)->where('due_at', '<', now())->count()], 'stages' => Status::orderBy('position')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'count' => (clone $q)->where('status_id', $s->id)->count()]), 'tasks' => $tasks->with('lead:id,title')->orderBy('due_at')->limit(8)->get()]);
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

        return Inertia::render('Core/Leads', [...$this->options($r->user()), 'table' => $table, 'leads' => $table['page'], 'filters' => $r->only('owner', 'status', 'source', 'priority', 'view'), 'preferences' => $r->user()->display_preferences ?? []]);
    }

    private function leadData(Request $r, bool $update = false): array
    {
        $data = $r->validate(['title' => 'sometimes|nullable|string|max:160', 'contact_id' => 'nullable|integer', 'company_id' => 'nullable|integer', 'owner_id' => 'nullable|integer', 'status_id' => ($update ? 'sometimes' : 'required').'|exists:crm_statuses,id', 'source' => 'sometimes|required|string|max:100', 'priority' => ['sometimes', Rule::in(['low', 'normal', 'high'])], 'custom_fields' => 'sometimes|array', 'version' => $update ? 'required|integer' : 'prohibited']);
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

        return Inertia::render('Core/Lead', [...$this->options($r->user()), 'lead' => $lead->load('contact', 'company', 'status', 'owner:id,name', 'notes.author:id,name', 'tasks.assignee:id,name', 'activity.actor:id,name'), 'editable' => Gate::allows('update', $lead), 'identityPermissions' => ['contact' => $lead->contact && Gate::allows('update', $lead->contact), 'company' => $lead->company && Gate::allows('update', $lead->company)], 'panels' => app(Modules::class)->panels($r->user())]);
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
        $selected = $r->input('table', 'contacts');
        validator(['table' => $selected], ['table' => ['required', Rule::in(['contacts', 'companies'])]])->validate();
        $engine = app(Query::class);
        $table = $engine->run(Access::identities($r->user(), $selected === 'contacts' ? Contact::class : Company::class), $selected, $r);
        $contacts = $selected === 'contacts' ? $table['page'] : Access::identities($r->user(), Contact::class)->paginate(20);
        $companies = $selected === 'companies' ? $table['page'] : Access::identities($r->user(), Company::class)->paginate(20);

        return Inertia::render('Core/People', ['table' => $table, 'contacts' => $contacts, 'companies' => $companies, 'companyOptions' => Access::identities($r->user(), Company::class)->orderBy('name')->limit(200)->get(['id', 'name'])]);
    }

    public function identity(Request $r, string $type, Identities $service)
    {
        $service->create($r->user(), $type, $r->all());

        return redirect('/people?table='.$type);
    }

    private function identityReturnTo(Request $r, string $type): string
    {
        $r->validate(['from_lead' => 'nullable|integer|min:1']);
        if ($r->filled('from_lead')) {
            abort_unless(Access::leads($r->user())->whereKey($r->integer('from_lead'))->exists(), 403);

            return '/leads/'.$r->integer('from_lead');
        }

        return '/people?table='.$type;
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

        return Inertia::render('Core/Person', ['identity' => $identity->only(['id', 'name', 'email', 'phone', 'company_id']), 'type' => $type, 'editable' => Gate::allows('update', $identity), 'companyOptions' => $companies, 'returnTo' => $this->identityReturnTo($r, $type), 'saveUrl' => '/people/'.$type.'/'.$id.($r->filled('from_lead') ? '?from_lead='.$r->integer('from_lead') : '')]);
    }

    public function updateIdentity(Request $r, string $type, int $id, Identities $service)
    {
        $returnTo = $this->identityReturnTo($r, $type);
        $service->update($r->user(), $type, $id, $r->all());

        return redirect($returnTo);
    }

    private function reminderQuery($u)
    {
        return Reminder::where('user_id', $u->id)->whereHas('task', fn ($q) => $q->whereColumn('version', 'crm_reminders.task_version')->where('status', 'open')->whereIn('lead_id', Access::leads($u)->whereNull('archived_at')->select('id')));
    }

    public function reminders(Request $r)
    {
        return Inertia::render('Core/Reminders', ['reminders' => $this->reminderQuery($r->user())->with('task.lead:id,title')->latest()->paginate(30)]);
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
