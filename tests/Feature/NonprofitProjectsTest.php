<?php

use App\Models\User;
use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\WorkItem;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReminders;
use Awove\Finance\Ledger;
use Awove\Nonprofit\Projects\Actuals;
use Awove\Nonprofit\Projects\Connections;
use Awove\Nonprofit\Projects\Links;
use Awove\Nonprofit\Projects\Models\Budget;
use Awove\Nonprofit\Projects\Models\Document;
use Awove\Nonprofit\Projects\Models\History;
use Awove\Nonprofit\Projects\Models\Meeting;
use Awove\Nonprofit\Projects\Models\PaymentLink;
use Awove\Nonprofit\Projects\Models\Project;
use Awove\Nonprofit\Projects\Models\ProjectWork;
use Awove\Nonprofit\Projects\Models\ResourceLink;
use Awove\Nonprofit\Projects\Records;
use Awove\Nonprofit\Projects\Work;
use Awove\Operations\Orders;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->npProjectsPath = tempnam(sys_get_temp_dir(), 'np-projects-');
    $this->npProjectsSettings = Installation::manifest();
    $this->npProjectsSettings['modules'] = ['nonprofit'];
    $this->npProjectsSettings['capabilities'] = ['sales' => false, 'nonprofit:projects' => true, 'nonprofit:programs' => false, 'nonprofit:fundraising' => false, 'finance:collections' => false, 'finance:expenses' => false, 'finance:payroll' => false];
    if (! isset(app(Modules::class)->installed()['finance'])) {
        foreach (['finance:collections', 'finance:expenses', 'finance:payroll'] as $cap) {
            unset($this->npProjectsSettings['capabilities'][$cap]);
        }
    }
    $this->npProjectsSettings['timezone'] = 'Asia/Jerusalem';
    npProjectReload($this);
    $this->artisan('crm:install')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00+02:00'));
});
afterEach(function () {
    unlink($this->npProjectsPath);
});
function npProjectReload($t): void
{
    file_put_contents($t->npProjectsPath, json_encode($t->npProjectsSettings));
    config(['core.manifest' => $t->npProjectsPath]);
}
function npProjectStaff(bool $all = false, bool $money = true, bool $docs = true): User
{
    $u = User::factory()->create(['name' => 'Shira Cohen', 'active' => true]);
    $u->assignRole('Employee');
    $permissions = ['nonprofit.projects.view.own', 'nonprofit.projects.create', 'nonprofit.projects.edit.own', 'nonprofit.projects.archive', 'nonprofit.projects.export'];
    if ($all) {
        $permissions = [...$permissions, 'nonprofit.projects.view.all', 'nonprofit.projects.edit.all', 'nonprofit.projects.assign'];
    }if ($money) {
        $permissions = [...$permissions, 'nonprofit.projects.budgets.view', 'nonprofit.projects.budgets.manage'];
    }if ($docs) {
        $permissions = [...$permissions, 'nonprofit.projects.documents.view', 'nonprofit.projects.documents.manage'];
    }$u->givePermissionTo($permissions);

    return $u;
}
function npProjectData(User $u, array $extra = []): array
{
    return [...['name' => 'Community learning plan', 'objective' => 'Offer a fictional neighborhood learning program.', 'owner_id' => $u->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'status' => 'planned'], ...$extra];
}
function npProject(User $u, array $extra = []): Project
{
    return app(Records::class)->save($u, npProjectData($u, $extra));
}
function npProjectWork(User $u, Project $p, string $kind = 'project-tasks', array $extra = []): WorkItem
{
    return app(Work::class)->save($u, $p->id, $kind, [...['project_version' => $p->fresh()->version, 'title' => $kind === 'project-tasks' ? 'Prepare learning materials' : 'Publish annual plan', 'description' => 'Confirm the fictional next step.', 'owner_id' => $u->id, 'priority' => 'normal', 'due_on' => '2026-10-30', 'status' => 'not-started'], ...$extra]);
}
function npProjectFinanceState(): array
{
    $a = [];
    foreach (['finance_records', 'finance_payments'] as $t) {
        $a[$t] = Schema::hasTable($t) ? DB::table($t)->count() : null;
    }

    return $a;
}
function npProjectSources($t): void
{
    $t->npProjectsSettings['modules'] = ['nonprofit', 'finance', 'operations', 'assets'];
    $t->npProjectsSettings['capabilities']['finance:collections'] = true;
    $t->npProjectsSettings['capabilities']['finance:expenses'] = true;
    npProjectReload($t);
}
function npProjectRelationship(User $u, string $role)
{
    $u->givePermissionTo(['identities.classifications.view', 'identities.classifications.manage']);
    $class = $role === 'supplier' ? Company::class : Contact::class;
    $identity = $class::create(['name' => $role === 'supplier' ? 'Carmel Supply' : 'Noam Levi', 'email' => Str::uuid().'@example.test', 'created_by' => $u->id, 'search_key' => $role]);

    return app(IdentityRelationships::class)->create($u, [($role === 'supplier' ? 'company_id' : 'contact_id') => $identity->id, 'role' => $role, 'responsible_id' => $u->id]);
}
function npProjectPayment(User $u, string $cap, string $amount)
{
    $u->givePermissionTo(array_map(fn ($action) => 'finance.'.$cap.'.'.$action, ['view.own', 'create', 'edit.own', 'record-payment']));
    $rel = npProjectRelationship($u, $cap === 'expenses' ? 'supplier' : 'customer');
    $ledger = app(Ledger::class);
    $r = $ledger->create($u, $cap, ['relationship_id' => $rel->id, 'owner_id' => $u->id, 'reference' => $cap === 'collections' ? 'Community receipt' : 'Learning supplies', 'description' => 'Fictional project transaction.', 'category' => $cap === 'expenses' ? 'Supplies' : null, 'total' => '1000.00', 'due_on' => '2026-10-31']);

    return $ledger->payment($u, $cap, $r->id, ['version' => $r->version, 'amount' => $amount, 'paid_on' => '2026-10-30', 'submission_key' => (string) Str::uuid()]);
}

it('persists a standalone nonfinancial project plan and preserves configured labels', function () {
    $u = npProjectStaff();
    $before = npProjectFinanceState();
    $this->actingAs($u)->post('/nonprofit/projects', npProjectData($u))->assertRedirect();
    $p = Project::sole();
    $this->get('/nonprofit/projects/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('record.name', 'Community learning plan')->where('record.objective', 'Offer a fictional neighborhood learning program.')->where('record.starts_on', '2026-10-01')->where('record.ends_on', '2026-12-31')->where('actuals.incoming.available', false)->where('actuals.incoming.total', null)->where('actuals.outgoing.total', null));
    expect(Budget::count())->toBe(0)->and(npProjectFinanceState())->toBe($before);
    $this->npProjectsSettings['nonprofit_project_statuses'] = ['planned' => 'Planned locally'];
    npProjectReload($this);
    $this->get('/nonprofit/projects/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('statuses.planned', 'Planned locally')->where('record.status', 'planned'));
    foreach (['sales', 'hr', 'finance', 'operations', 'assets', 'nonprofit:programs', 'nonprofit:fundraising'] as $cap) {
        expect(app(Modules::class)->enabled($cap))->toBeFalse();
    }
});
it('enforces own and all-only read edit assignment and direct export scopes', function () {
    $u = npProjectStaff();
    $other = npProjectStaff();
    $mine = npProject($u);
    $hidden = npProject($other, ['name' => 'Private annual plan']);
    $this->actingAs($u)->get('/nonprofit/projects/'.$hidden->id)->assertNotFound();
    $this->patchJson('/nonprofit/projects/'.$mine->id, ['version' => 1, 'owner_id' => $other->id])->assertForbidden();
    $csv = $this->get('/nonprofit/projects/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Private annual plan');
    $all = npProjectStaff(true);
    $all->revokePermissionTo('nonprofit.projects.view.own');
    $this->actingAs($all)->get('/nonprofit/projects/'.$hidden->id)->assertOk();
    $this->patch('/nonprofit/projects/'.$hidden->id, ['version' => 1, 'name' => 'Reviewed annual plan'])->assertRedirect();
    expect($hidden->fresh()->name)->toBe('Reviewed annual plan');
    $u->givePermissionTo('nonprofit.projects.assign');
    $this->actingAs($u)->patch('/nonprofit/projects/'.$mine->id, ['version' => 1, 'owner_id' => $other->id])->assertRedirect('/nonprofit/projects');
    $this->get('/nonprofit/projects/'.$mine->id)->assertNotFound();
    expect($u->fresh()->can('nonprofit.projects.view.all'))->toBeFalse();
});
it('validates complete dates required fields and optimistic edits without partial history', function () {
    $u = npProjectStaff();
    $this->actingAs($u)->postJson('/nonprofit/projects', npProjectData($u, ['name' => '  ']))->assertUnprocessable();
    $this->postJson('/nonprofit/projects', npProjectData($u, ['ends_on' => '2026-02-30']))->assertUnprocessable();
    $this->postJson('/nonprofit/projects', npProjectData($u, ['ends_on' => '2026-09-30']))->assertUnprocessable();
    $p = npProject($u);
    $this->patch('/nonprofit/projects/'.$p->id, ['version' => 1, 'name' => 'Updated plan'])->assertRedirect();
    $this->patchJson('/nonprofit/projects/'.$p->id, ['version' => 1, 'name' => 'Stale plan', 'planned_budget' => '100.00'])->assertConflict();
    expect($p->fresh()->name)->toBe('Updated plan')->and(Budget::count())->toBe(0)->and(History::count())->toBe(2);
});
it('retains exact append-only budget revisions with separate field permissions', function () {
    $u = npProjectStaff();
    $before = npProjectFinanceState();
    $p = npProject($u, ['planned_budget' => '100.10']);
    $this->actingAs($u)->patch('/nonprofit/projects/'.$p->id, ['version' => 1, 'planned_budget' => '200.20'])->assertRedirect();
    expect(Budget::orderBy('id')->pluck('amount')->all())->toBe(['100.10', '200.20'])->and(npProjectFinanceState())->toBe($before);
    $this->patchJson('/nonprofit/projects/'.$p->id, ['version' => 2, 'planned_budget' => ''])->assertUnprocessable();
    $this->patchJson('/nonprofit/projects/'.$p->id, ['version' => 2, 'planned_budget' => 0.1])->assertUnprocessable();
    $this->patchJson('/nonprofit/projects/'.$p->id, ['version' => 2, 'planned_budget' => '9999999999999.00'])->assertUnprocessable();
    expect(fn () => Budget::first()->update(['amount' => '0.00']))->toThrow(LogicException::class)->and(fn () => Budget::first()->delete())->toThrow(LogicException::class);
    $u->revokePermissionTo('nonprofit.projects.budgets.view');
    $response = $this->get('/nonprofit/projects/'.$p->id)->assertOk();
    expect(json_encode($response->inertiaProps()))->not->toContain('100.10', '200.20');
    $this->patch('/nonprofit/projects/'.$p->id, ['version' => 2, 'objective' => 'Retain restricted budget.'])->assertRedirect();
    $this->patchJson('/nonprofit/projects/'.$p->id, ['version' => 3, 'planned_budget' => '1.00'])->assertForbidden();
    expect(Budget::count())->toBe(2);
});
it('keeps empty project plans nonmonetary and blocks budget changes on currency conflict', function () {
    $u = npProjectStaff();
    $p = npProject($u);
    $currency = Currency::current();
    $this->npProjectsSettings['currency'] = $currency === 'USD' ? 'EUR' : 'USD';
    npProjectReload($this);
    expect(app(Currency::class)->consistent())->toBeTrue();
    $this->actingAs($u)->patch('/nonprofit/projects/'.$p->id, ['version' => 1, 'planned_budget' => '10.01'])->assertRedirect();
    $old = Budget::sole();
    $this->npProjectsSettings['currency'] = $currency;
    npProjectReload($this);
    $this->patchJson('/nonprofit/projects/'.$p->id, ['version' => 2, 'planned_budget' => '20.01'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    $this->patch('/nonprofit/projects/'.$p->id, ['version' => 2, 'objective' => 'Nonmonetary changes remain possible.'])->assertRedirect();
    expect(Budget::sole()->amount)->toBe($old->amount)->and(Budget::sole()->currency)->toBe($old->currency);
});
it('creates typed shared tasks milestones and guards cross-project and passed-parent writes', function () {
    $u = npProjectStaff();
    $p = npProject($u);
    $other = npProject(npProjectStaff());
    $w = npProjectWork($u, $p);
    $m = npProjectWork($u, $p, 'project-milestones');
    expect($w->module)->toBe('nonprofit')->and($w->kind)->toBe('project-tasks')->and($m->kind)->toBe('project-milestones')->and(ProjectWork::count())->toBe(2);
    $this->actingAs($u)->get('/nonprofit/projects?section=project-tasks')->assertInertia(fn (Assert $page) => $page->where('table.page.data.0.cells.title', 'Prepare learning materials')->where('table.page.data.0.cells.project', 'Community learning plan'));
    $this->get('/nonprofit/projects?section=project-milestones')->assertInertia(fn (Assert $page) => $page->where('table.page.data.0.cells.title', 'Publish annual plan'));
    $this->actingAs($u)->get('/nonprofit/project-milestones/'.$w->id)->assertNotFound();
    $this->postJson('/nonprofit/projects/'.$other->id.'/work/project-tasks', ['project_version' => $other->version, 'title' => 'Hidden work', 'due_on' => '2026-11-01'])->assertNotFound();
    expect(fn () => app(Work::class)->createWithin($u, $other, 'project-tasks', ['title' => 'Bypass', 'owner_id' => null, 'due_on' => '2026-11-01']))->toThrow(ModelNotFoundException::class);
    $closed = npProject($u, ['status' => 'completed']);
    expect(fn () => app(Work::class)->createWithin($u, $closed, 'project-tasks', ['title' => 'Closed bypass', 'due_on' => '2026-11-01']))->toThrow(HttpException::class);
    expect(ProjectWork::count())->toBe(2);
    $orphan = app(WorkItems::class)->create($u, 'nonprofit', 'project-tasks', ['title' => 'Unlinked generic item', 'due_on' => '2026-11-01']);
    expect(app(WorkItems::class)->query($u, 'nonprofit', 'project-tasks')->whereKey($orphan->id)->exists())->toBeFalse();
});
it('requires each work owner already to see the parent and retains unchanged historical string owners', function () {
    $u = npProjectStaff(true);
    $own = npProject($u);
    $candidate = npProjectStaff();
    $this->actingAs($u)->postJson('/nonprofit/projects/'.$own->id.'/work/project-tasks', ['project_version' => 1, 'title' => 'Prepare plan', 'owner_id' => $candidate->id, 'due_on' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $candidate->givePermissionTo('nonprofit.projects.view.all');
    $w = npProjectWork($u, $own, 'project-tasks', ['owner_id' => $candidate->id]);
    $candidate->update(['active' => false]);
    $this->patch('/nonprofit/project-tasks/'.$w->id, ['project_version' => $own->fresh()->version, 'version' => $w->version, 'owner_id' => (string) $candidate->id, 'description' => 'Retain inactive historical owner.'])->assertRedirect();
    expect($w->fresh()->owner_id)->toBe($candidate->id)->and($w->fresh()->description)->toBe('Retain inactive historical owner.');
});
it('enforces child ownership independently and invalidates stale parent and child edits atomically', function () {
    $u = npProjectStaff();
    $u->givePermissionTo('nonprofit.projects.assign');
    $p = npProject($u);
    $recipient = npProjectStaff(true);
    $w = npProjectWork($u, $p, 'project-tasks', ['owner_id' => $recipient->id]);
    $this->actingAs($u)->get('/nonprofit/project-tasks/'.$w->id)->assertNotFound();
    $this->get('/nonprofit/projects/'.$p->id)->assertInertia(fn (Assert $page) => $page->has('work.project-tasks', 0));
    $own = npProjectWork($u, $p);
    $payload = ['project_version' => $p->fresh()->version, 'version' => $own->version, 'title' => 'A revised task'];
    $this->patch('/nonprofit/project-tasks/'.$own->id, $payload)->assertRedirect();
    $before = History::count();
    $this->patchJson('/nonprofit/project-tasks/'.$own->id, $payload)->assertConflict();
    expect(History::count())->toBe($before)->and($own->fresh()->title)->toBe('A revised task');
});
it('records meetings decisions and explicit shared follow-ups with versioned corrections', function () {
    $u = npProjectStaff();
    $p = npProject($u);
    $input = ['project_version' => 1, 'held_on' => '2026-10-30', 'summary' => 'Reviewed the fictional program.', 'decisions' => 'Prepare materials before the next meeting.', 'follow_up' => ['title' => 'Send learning outline', 'owner_id' => $u->id, 'due_on' => '2026-11-02']];
    $this->actingAs($u)->post('/nonprofit/projects/'.$p->id.'/meetings', $input)->assertRedirect();
    $meeting = Meeting::sole();
    $follow = WorkItem::sole();
    expect($meeting->follow_up_work_item_id)->toBe($follow->id)->and(ProjectWork::sole()->project_id)->toBe($p->id);
    $this->get('/nonprofit/projects/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('meetings.0.follow_up.title', 'Send learning outline')->where('meetings.0.follow_up.due_on', '2026-11-02'));
    $patch = ['project_version' => $p->fresh()->version, 'version' => 1, 'held_on' => '2026-10-30', 'summary' => 'Corrected the meeting summary.', 'decisions' => 'Retain the original follow-up.'];
    $this->patch('/nonprofit/projects/'.$p->id.'/meetings/'.$meeting->id, $patch)->assertRedirect();
    $this->patchJson('/nonprofit/projects/'.$p->id.'/meetings/'.$meeting->id, $patch)->assertConflict();
    expect($meeting->fresh()->follow_up_work_item_id)->toBe($follow->id)->and(WorkItem::count())->toBe(1);
});
it('rolls back an invalid meeting follow-up without saving partial meeting or work history', function () {
    $u = npProjectStaff();
    $p = npProject($u);
    $this->actingAs($u)->postJson('/nonprofit/projects/'.$p->id.'/meetings', ['project_version' => 1, 'held_on' => '2026-10-30', 'summary' => 'Do not persist partially.', 'follow_up' => ['title' => 'Missing due date']])->assertUnprocessable();
    expect(Meeting::count())->toBe(0)->and(WorkItem::count())->toBe(0)->and($p->fresh()->version)->toBe(1)->and(History::count())->toBe(1);
});
it('retains archived and completed parent work read-only while suppressing generic reminders', function () {
    $u = npProjectStaff();
    $p = npProject($u);
    $w = npProjectWork($u, $p);
    $reminders = app(WorkReminders::class);
    $reminders->enqueueDue();
    $notice = WorkReminder::where('module', 'nonprofit')->sole();
    expect($reminders->preview($notice))->not->toBeNull();
    $this->actingAs($u)->patch('/nonprofit/projects/'.$p->id, ['version' => $p->fresh()->version, 'status' => 'completed'])->assertRedirect();
    expect($reminders->preview($notice))->toBeNull()->and(app(WorkItems::class)->query($u, 'nonprofit', 'project-tasks')->whereKey($w->id)->exists())->toBeTrue()->and(app(WorkItems::class)->editable($u, $w->fresh()))->toBeFalse();
    $this->get('/nonprofit/project-tasks/'.$w->id)->assertOk();
    $this->patch('/nonprofit/projects/'.$p->id, ['version' => $p->fresh()->version, 'status' => 'in-progress'])->assertRedirect();
    expect(app(WorkItems::class)->editable($u, $w->fresh()))->toBeTrue();
    $this->post('/nonprofit/projects/'.$p->id.'/archive', ['version' => $p->fresh()->version])->assertRedirect();
    expect($reminders->preview($notice))->toBeNull()->and($w->fresh()->status)->toBe('not-started');
    $this->get('/nonprofit/projects/'.$p->id)->assertOk();
    $this->get('/nonprofit/project-tasks/'.$w->id)->assertOk();
});
it('stops stale reminders and generic work visibility after parent ownership permission or capability loss', function () {
    $u = npProjectStaff();
    $u->givePermissionTo('nonprofit.projects.assign');
    $other = npProjectStaff();
    $p = npProject($u);
    $w = npProjectWork($u, $p);
    $r = app(WorkReminders::class);
    $r->enqueueDue();
    $notice = WorkReminder::where('module', 'nonprofit')->sole();
    $this->actingAs($u)->patch('/nonprofit/projects/'.$p->id, ['version' => $p->fresh()->version, 'owner_id' => $other->id])->assertRedirect();
    expect($r->preview($notice))->toBeNull()->and(app(WorkItems::class)->query($u, 'nonprofit', 'project-tasks')->count())->toBe(0);
    $this->npProjectsSettings['capabilities']['nonprofit:projects'] = false;
    npProjectReload($this);
    $this->get('/nonprofit/projects')->assertNotFound();
    $this->get('/nonprofit/project-tasks/'.$w->id)->assertNotFound();
    expect($r->preview($notice))->toBeNull();
    $this->npProjectsSettings['capabilities']['nonprofit:projects'] = true;
    npProjectReload($this);
    expect(Project::count())->toBe(1)->and(WorkItem::count())->toBe(1);
});
it('scopes private safe document redirects and retained archival independently', function () {
    $u = npProjectStaff();
    $p = npProject($u);
    $this->actingAs($u)->post('/nonprofit/projects/'.$p->id.'/documents', ['project_version' => 1, 'name' => 'Annual plan document', 'url' => 'https://documents.example.test/annual-plan'])->assertRedirect();
    $doc = Document::sole();
    $this->get('/nonprofit/project-documents/'.$doc->id)->assertRedirect('https://documents.example.test/annual-plan');
    $this->postJson('/nonprofit/projects/'.$p->id.'/documents', ['project_version' => $p->fresh()->version, 'name' => 'Unsafe', 'url' => 'javascript:alert(1)'])->assertUnprocessable();
    $u->revokePermissionTo('nonprofit.projects.documents.view');
    $this->get('/nonprofit/project-documents/'.$doc->id)->assertForbidden();
    $response = $this->get('/nonprofit/projects/'.$p->id)->assertOk();
    expect(json_encode($response->inertiaProps()))->not->toContain('Annual plan document', 'documents.example.test');
    $u->givePermissionTo('nonprofit.projects.documents.view');
    $payload = ['project_version' => $p->fresh()->version, 'version' => 1];
    $this->post('/nonprofit/project-documents/'.$doc->id.'/archive', $payload)->assertRedirect();
    $this->postJson('/nonprofit/project-documents/'.$doc->id.'/archive', $payload)->assertConflict();
    $this->get('/nonprofit/project-documents/'.$doc->id)->assertRedirect('https://documents.example.test/annual-plan');
});
it('matches advanced table filters sorting and safe exports while protecting hidden budget columns and dormant layouts', function () {
    $u = npProjectStaff();
    $p = npProject($u, ['name' => '=HYPERLINK(A1)', 'planned_budget' => '100.01']);
    npProject($u, ['name' => 'Other plan']);
    npProject(npProjectStaff(), ['name' => 'Private plan']);
    $query = ['q' => 'HYPERLINK', 'filters' => [['id' => 'status', 'op' => 'eq', 'value' => 'planned']], 'sorts' => [['id' => 'name', 'direction' => 'asc']]];
    $this->actingAs($u)->get('/nonprofit/projects?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1)->where('table.page.data.0.cells.name', '=HYPERLINK(A1)')->where('table.page.data.0.cells.planned_budget', '100.01'));
    $csv = $this->get('/nonprofit/projects/export?'.http_build_query($query))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK(A1)", '100.01')->not->toContain('Other plan', 'Private plan');
    $this->postJson('/preferences', ['table' => 'nonprofit.projects', 'columns' => ['name', 'planned_budget', 'status']])->assertOk();
    $before = $u->fresh()->display_preferences;
    $this->npProjectsSettings['capabilities']['nonprofit:projects'] = false;
    npProjectReload($this);
    $this->postJson('/preferences', ['table' => 'nonprofit.projects', 'columns' => ['name']])->assertUnprocessable();
    expect($u->fresh()->display_preferences)->toBe($before);
    $this->npProjectsSettings['capabilities']['nonprofit:projects'] = true;
    npProjectReload($this);
    $u->revokePermissionTo('nonprofit.projects.budgets.view');
    $this->getJson('/nonprofit/projects?'.http_build_query(['filters' => [['id' => 'planned_budget', 'op' => 'gt', 'value' => '1']]]))->assertUnprocessable();
    $csv = $this->get('/nonprofit/projects/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('planned_budget', '100.01');
    $u->revokePermissionTo('nonprofit.projects.export');
    $this->get('/nonprofit/projects/export')->assertForbidden();
});
it('rejects cached deactivated actors and disabled or unknown optional source selectors', function () {
    $u = npProjectStaff();
    $p = npProject($u);
    $this->actingAs($u)->postJson('/nonprofit/projects/'.$p->id.'/resources', ['project_version' => 1, 'target_type' => 'invented', 'target_id' => 1])->assertUnprocessable();
    $this->postJson('/nonprofit/projects/'.$p->id.'/resources', ['project_version' => 1, 'target_type' => 'asset', 'target_id' => 1])->assertForbidden();
    $this->postJson('/nonprofit/projects/'.$p->id.'/payments', ['project_version' => 1, 'direction' => 'incoming', 'payment_id' => 1])->assertForbidden();
    $cached = $u->fresh();
    $u->update(['active' => false]);
    expect(app(Connections::class)->resourceOptions($cached)['asset']['available'])->toBeFalse()->and(app(Connections::class)->paymentOptions($cached)['incoming']['available'])->toBeFalse();
    expect(ResourceLink::count())->toBe(0)->and(PaymentLink::count())->toBe(0);
});
it('compares whole actual incoming and outgoing Finance payments separately with exact dates and no duplicated transaction', function () {
    if (! class_exists(Ledger::class) || ! Schema::hasTable('finance_payments')) {
        $this->markTestSkipped('Requires installed Finance integration.');
    }npProjectSources($this);
    $u = npProjectStaff();
    $incoming = npProjectPayment($u, 'collections', '100.10');
    $outgoing = npProjectPayment($u, 'expenses', '40.02');
    $p = npProject($u, ['planned_budget' => '200.00']);
    $before = npProjectFinanceState();
    foreach ([['incoming', $incoming], ['outgoing', $outgoing]] as [$direction,$payment]) {
        app(Links::class)->addPayment($u, $p->id, ['project_version' => $p->fresh()->version, 'direction' => $direction, 'payment_id' => $payment->id]);
    }$actual = app(Actuals::class)->forProject($u, $p);
    expect($actual['planned_budget'])->toBe('200.00')->and($actual['incoming']['total'])->toBe('100.10')->and($actual['outgoing']['total'])->toBe('40.02')->and(npProjectFinanceState())->toBe($before);
    $filtered = app(Actuals::class)->forProject($u, $p, '2026-10-31', '2026-10-31');
    expect($filtered['incoming']['total'])->toBe('0.00')->and($filtered['outgoing']['total'])->toBe('0.00');
    $other = npProject($u);
    $this->actingAs($u)->postJson('/nonprofit/projects/'.$other->id.'/payments', ['project_version' => 1, 'direction' => 'incoming', 'payment_id' => $incoming->id])->assertUnprocessable();
    expect(PaymentLink::count())->toBe(2);
})->group('projects-integrations');
it('redacts hidden Finance associations and reports unavailable totals without partial or fabricated results', function () {
    if (! class_exists(Ledger::class) || ! Schema::hasTable('finance_payments')) {
        $this->markTestSkipped('Requires installed Finance integration.');
    }npProjectSources($this);
    $u = npProjectStaff();
    $payment = npProjectPayment($u, 'collections', '99.99');
    $p = npProject($u);
    app(Links::class)->addPayment($u, $p->id, ['project_version' => 1, 'direction' => 'incoming', 'payment_id' => $payment->id]);
    $cached = $u->fresh();
    $u->revokePermissionTo('finance.collections.view.own');
    expect(app(Connections::class)->paymentOptions($cached)['incoming']['available'])->toBeFalse();
    $this->actingAs($u)->get('/nonprofit/projects/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('actuals.incoming.available', false)->where('actuals.incoming.total', null)->where('payments.0.restricted', true));
    app(Records::class)->save($u, ['version' => $p->fresh()->version, 'objective' => 'Keep the hidden association.'], $p->id);
    expect(PaymentLink::sole()->payment_id)->toBe($payment->id);
    $this->postJson('/nonprofit/projects/'.$p->id.'/payments/'.PaymentLink::sole()->id.'/archive', ['project_version' => $p->fresh()->version, 'version' => 1])->assertForbidden();
    $u->givePermissionTo('finance.collections.view.own');
    expect(app(Actuals::class)->forProject($u, $p)['incoming']['total'])->toBe('99.99');
})->group('projects-integrations');
it('links existing scoped Assets and Operations resources only and suppresses stale cached access', function () {
    if (! class_exists(Awove\Assets\Records::class) || ! class_exists(Orders::class) || ! Schema::hasTable('assets_register') || ! Schema::hasTable('operations_orders')) {
        $this->markTestSkipped('Requires installed Assets and Operations integration.');
    }npProjectSources($this);
    $u = npProjectStaff();
    $u->givePermissionTo(['assets.view.own', 'assets.create', 'assets.edit.own', 'operations.view.own', 'operations.create', 'operations.edit.own']);
    $asset = app(Awove\Assets\Records::class)->save($u, ['code' => 'PROJECT-101', 'name' => 'Community projector', 'type' => 'Equipment', 'location' => 'Community room', 'owner_id' => $u->id, 'status' => 'available']);
    $supplier = npProjectRelationship($u, 'supplier');
    $order = app(Orders::class)->create($u, ['reference' => 'PROJECT-ORDER-101', 'description' => 'Learning materials', 'supplier_relationship_id' => $supplier->id, 'owner_id' => $u->id, 'quantity' => '2.000', 'agreed_total' => '10.00', 'ordered_on' => '2026-10-31', 'status' => 'ordered']);
    $p = npProject($u);
    foreach ([['asset', $asset], ['operations-order', $order]] as [$type,$target]) {
        app(Links::class)->addResource($u, $p->id, ['project_version' => $p->fresh()->version, 'target_type' => $type, 'target_id' => $target->id]);
    }expect(ResourceLink::count())->toBe(2)->and(DB::table('assets_register')->count())->toBe(1)->and(DB::table('operations_orders')->count())->toBe(1);
    $cached = $u->fresh();
    $u->revokePermissionTo('operations.view.own');
    expect(app(Connections::class)->resourceOptions($cached)['operations-order']['available'])->toBeFalse();
    $this->npProjectsSettings['modules'] = ['nonprofit'];
    npProjectReload($this);
    $rows = app(Links::class)->resources($u, $p);
    expect($rows)->toBe([['restricted' => true], ['restricted' => true]])->and(ResourceLink::count())->toBe(2);
    $this->npProjectsSettings['modules'] = ['nonprofit', 'assets', 'operations'];
    npProjectReload($this);
    $u->givePermissionTo('operations.view.own');
    expect(app(Links::class)->resources($u, $p)[0]['target']['label'])->toContain('Community projector');
})->group('projects-integrations');
