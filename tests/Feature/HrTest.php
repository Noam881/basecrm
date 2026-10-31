<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Models\EmployeeReference;
use Awove\Core\Models\WorkItem;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Services\WorkReminders;
use Awove\Hr\Access;
use Awove\Hr\HrModule;
use Awove\Hr\Models\Candidate;
use Awove\Hr\Models\EmploymentCase;
use Awove\Hr\Models\EmploymentProfile;
use Awove\Hr\Models\HrDocument;
use Awove\Hr\Models\HrHistory;
use Awove\Hr\Models\Vacancy;
use Awove\Hr\Records;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->hrManifest = tempnam(sys_get_temp_dir(), 'crm-hr-');
    $this->hrData = Installation::manifest();
    $this->hrData['modules'] = ['hr'];
    $this->hrData['capabilities']['sales'] = false;
    file_put_contents($this->hrManifest, json_encode($this->hrData));
    config(['core.manifest' => $this->hrManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    if (is_file($this->hrManifest)) {
        unlink($this->hrManifest);
    }
});
function hrStaff(string $name = 'Noa Cohen', bool $all = false): User
{
    $u = User::factory()->create(['name' => $name]);
    $u->assignRole('Employee');
    $p = ['employees.references.view', 'employees.references.manage'];
    foreach (['employment', 'recruitment', 'cases'] as $section) {
        $p = [...$p, 'hr.'.$section.'.view.own', 'hr.'.$section.'.create', 'hr.'.$section.'.edit.own', 'hr.'.$section.'.archive', 'hr.'.$section.'.export'];
        if ($all) {
            $p = [...$p, 'hr.'.$section.'.view.all', 'hr.'.$section.'.edit.all', 'hr.'.$section.'.assign'];
        }
    }
    $u->givePermissionTo($p);

    return $u;
}
function hrEmployee(User $u, string $name = 'Yael Shalev'): EmployeeReference
{
    return app(EmployeeReferences::class)->create($u, ['name' => $name]);
}
function hrProfile(User $u, EmployeeReference $employee, array $change = []): array
{
    return [...['employee_reference_id' => $employee->id, 'role_name' => 'Project coordinator', 'role_description' => 'Coordinate agreed work.', 'responsibilities' => 'Maintain delivery records.', 'email' => 'yael.private@example.test', 'phone' => '+972 50 000 0000', 'manager_employee_reference_id' => null, 'start_on' => '2026-10-20', 'employment_status' => 'active', 'owner_id' => $u->id], ...$change];
}
function hrVacancy(User $u): Vacancy
{
    return app(Records::class)->save($u, 'vacancies', ['role_name' => 'Operations coordinator', 'description' => 'Coordinate customer work.', 'opened_on' => '2026-10-20', 'status' => 'open', 'owner_id' => $u->id]);
}
function hrCandidate(User $u, Vacancy $vacancy, array $change = []): Candidate
{
    return app(Records::class)->save($u, 'candidates', [...['vacancy_id' => $vacancy->id, 'name' => 'Tamar Erez', 'email' => 'tamar.candidate@example.test', 'phone' => '+972 50 000 0001', 'notes' => 'Private interview notes.', 'stage' => 'new', 'next_action_on' => now(Installation::settings()['timezone'])->toDateString(), 'owner_id' => $u->id], ...$change]);
}
function hrCase(User $u, EmployeeReference $employee, array $change = []): EmploymentCase
{
    return app(Records::class)->save($u, 'cases', [...['employee_reference_id' => $employee->id, 'kind' => 'onboarding', 'name' => 'Prepare first working day', 'due_on' => now(Installation::settings()['timezone'])->toDateString(), 'owner_id' => $u->id], ...$change]);
}
function hrItem(User $u, EmploymentCase $case, array $change = []): WorkItem
{
    return app(Records::class)->checklist($u, $case->id, [...['title' => 'Review required documents', 'description' => 'Record the manual document review.', 'owner_id' => $u->id, 'priority' => 'normal', 'status' => 'not-started', 'due_on' => $case->getRawOriginal('due_on'), 'category' => 'documents', 'required' => true], ...$change]);
}

it('keeps private employment separate from contacts and non-login shared employee references', function () {
    $u = hrStaff();
    $employee = hrEmployee($u);
    $manager = hrEmployee($u, 'Uri Levi');
    $contacts = Contact::count();
    $users = User::count();
    $this->actingAs($u)->post('/hr/employees', hrProfile($u, $employee, ['manager_employee_reference_id' => $manager->id]))->assertRedirect();
    $profile = EmploymentProfile::sole();
    expect(Contact::count())->toBe($contacts)->and(User::count())->toBe($users)->and($employee->toArray())->toEqual(['id' => $employee->id, 'name' => 'Yael Shalev', 'active' => true]);
    $this->get('/hr/employees/'.$profile->id)->assertInertia(fn (Assert $p) => $p->where('record.employee.name', 'Yael Shalev')->where('record.manager.name', 'Uri Levi')->where('record.email', 'yael.private@example.test')->where('record.start_on', '2026-10-20'));
    $this->post('/hr/employees', hrProfile($u, $employee))->assertSessionHasErrors('employee_reference_id');
    $u->revokePermissionTo('employees.references.view');
    $this->get('/hr/employees/'.$profile->id)->assertInertia(fn (Assert $p) => $p->where('record.employee', null)->where('record.employee_reference_id', null)->where('record.manager_employee_reference_id', null)->where('record.manager_restricted', true)->where('employees', []));
    $this->patch('/hr/employees/'.$profile->id, ['role_name' => 'Senior coordinator', 'version' => 1])->assertRedirect();
    expect($profile->fresh()->employee_reference_id)->toBe($employee->id)->and($profile->fresh()->manager_employee_reference_id)->toBe($manager->id);
});
it('enforces employment ownership versions and fresh write actors while retaining inactive historical owners', function () {
    $u = hrStaff();
    $other = hrStaff('Shira Levi');
    $employee = hrEmployee($u);
    $profile = app(Records::class)->save($u, 'employees', hrProfile($u, $employee));
    $this->actingAs($other)->get('/hr/employees/'.$profile->id)->assertNotFound();
    $this->patch('/hr/employees/'.$profile->id, ['role_name' => 'Unauthorized', 'version' => 1])->assertNotFound();
    $this->actingAs($u)->patch('/hr/employees/'.$profile->id, ['responsibilities' => 'Updated manual responsibilities.', 'version' => 1])->assertRedirect();
    $this->patch('/hr/employees/'.$profile->id, ['version' => 1])->assertConflict();
    expect(HrHistory::count())->toBe(2);
    DB::table('users')->where('id', $u->id)->update(['active' => false]);
    expect(fn () => app(Records::class)->save($u, 'employees', ['version' => 2], $profile->id))->toThrow(HttpException::class);
    $manager = hrStaff('Oren Cohen', true);
    $this->actingAs($manager)->patch('/hr/employees/'.$profile->id, ['role_name' => 'Retained owner coordinator', 'version' => 2])->assertRedirect();
    expect($profile->fresh()->owner_id)->toBe($u->id);
});
it('retains manual recruitment stages and never creates employee or contact identities when marked hired', function () {
    $u = hrStaff();
    $v = hrVacancy($u);
    $c = hrCandidate($u, $v);
    $before = EmployeeReference::count();
    $contacts = Contact::count();
    foreach (['screening', 'interview', 'offer', 'hired', 'rejected'] as $index => $stage) {
        $this->actingAs($u)->patch('/hr/candidates/'.$c->id, ['stage' => $stage, 'version' => $index + 1])->assertRedirect();
    }
    expect($c->fresh()->stage)->toBe('rejected')->and(HrHistory::where('candidate_id', $c->id)->count())->toBe(6)->and(EmployeeReference::count())->toBe($before)->and(Contact::count())->toBe($contacts);
    $employee = hrEmployee($u);
    $this->patch('/hr/candidates/'.$c->id, ['stage' => 'hired', 'employee_reference_id' => $employee->id, 'version' => 6])->assertRedirect();
    expect($c->fresh()->employee_reference_id)->toBe($employee->id);
    $this->patch('/hr/candidates/'.$c->id, ['stage' => 'invented', 'version' => 7])->assertSessionHasErrors('stage');
});
it('redacts inaccessible vacancy and employee IDs and preserves their links on unrelated candidate edits', function () {
    $manager = hrStaff('Noa Cohen', true);
    $owner = hrStaff('Uri Erez');
    $v = hrVacancy($owner);
    $employee = hrEmployee($manager);
    $c = hrCandidate($manager, $v, ['stage' => 'hired', 'employee_reference_id' => $employee->id]);
    $manager->revokePermissionTo(['hr.recruitment.view.all', 'employees.references.view']);
    $this->actingAs($manager)->get('/hr/candidates/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('record.vacancy', null)->where('record.vacancy_id', null)->where('record.employee_reference_id', null)->where('record.vacancy_restricted', true));
    $this->patch('/hr/candidates/'.$c->id, ['notes' => 'Updated interview notes.', 'vacancy_id' => null, 'vacancy_id_action' => 'keep', 'employee_reference_id' => null, 'employee_reference_id_action' => 'keep', 'version' => 1])->assertRedirect();
    expect($c->fresh()->vacancy_id)->toBe($v->id)->and($c->fresh()->employee_reference_id)->toBe($employee->id);
});
it('gates CV document metadata links and redirects by both parent scope and explicit document permissions', function () {
    $u = hrStaff();
    $v = hrVacancy($u);
    $c = hrCandidate($u, $v);
    $payload = ['name' => 'Candidate CV', 'url' => 'https://example.test/private-cv.pdf'];
    $this->actingAs($u)->post('/hr/candidates/'.$c->id.'/documents', $payload)->assertForbidden();
    $u->givePermissionTo(['hr.documents.view', 'hr.documents.manage']);
    $this->post('/hr/candidates/'.$c->id.'/documents', $payload)->assertRedirect();
    $doc = HrDocument::sole();
    $this->get('/hr/documents/'.$doc->id)->assertRedirect($payload['url']);
    $this->get('/hr/candidates/'.$c->id)->assertInertia(fn (Assert $p) => $p->has('documents', 1)->missing('documents.0.url'));
    $u->revokePermissionTo('hr.documents.view');
    $this->get('/hr/candidates/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('documents', []));
    $this->get('/hr/documents/'.$doc->id)->assertForbidden();
    $other = hrStaff('Shira Levi');
    $other->givePermissionTo('hr.documents.view');
    $this->actingAs($other)->get('/hr/documents/'.$doc->id)->assertNotFound();
    $u->givePermissionTo('hr.documents.view');
    $this->actingAs($u)->post('/hr/candidates/'.$c->id.'/documents', ['name' => 'Unsafe', 'url' => 'https://name:secret@example.test/cv'])->assertSessionHasErrors('url');
    $this->post('/hr/documents/'.$doc->id.'/archive', ['version' => 1])->assertRedirect();
    expect($doc->fresh()->url)->toBe($payload['url'])->and($doc->fresh()->archived_at)->not->toBeNull();
});
it('uses shared manual checklist tasks and rejects skipped required work or incomplete case completion', function () {
    $u = hrStaff();
    $employee = hrEmployee($u);
    $case = hrCase($u, $employee);
    $item = hrItem($u, $case);
    expect($item->module)->toBe('hr')->and($item->kind)->toBe('checklist')->and(DB::table('hr_case_items')->value('work_item_id'))->toBe($item->id);
    $this->actingAs($u)->post('/hr/cases/'.$case->id.'/complete', ['version' => 2, 'completed_on' => '2026-10-24'])->assertSessionHasErrors('case');
    $this->patch('/hr/checklist/'.$item->id, ['status' => 'skipped', 'category' => 'documents', 'required' => true, 'version' => 1])->assertSessionHasErrors('status');
    $this->patch('/hr/checklist/'.$item->id, ['status' => 'done', 'completed_on' => '2026-10-24', 'category' => 'documents', 'required' => true, 'version' => 1])->assertRedirect();
    $this->post('/hr/cases/'.$case->id.'/complete', ['version' => 3, 'completed_on' => '2026-10-24'])->assertRedirect();
    expect($case->fresh()->toArray()['completed_on'])->toBe('2026-10-24')->and($item->fresh()->toArray()['completed_on'])->toBe('2026-10-24')->and($employee->fresh()->active)->toBeTrue();
    $this->post('/hr/cases/'.$case->id.'/complete', ['version' => 3, 'completed_on' => '2026-10-24'])->assertForbidden();
    $this->patch('/hr/checklist/'.$item->id, ['title' => 'Change completed work', 'category' => 'documents', 'required' => true, 'version' => 2])->assertForbidden();
    expect(fn () => HrHistory::first()->delete())->toThrow(LogicException::class);
});
it('requires optional work to be explicitly skipped instead of claiming it was completed', function () {
    $u = hrStaff();
    $case = hrCase($u, hrEmployee($u));
    $item = hrItem($u, $case, ['required' => false]);
    $this->actingAs($u)->post('/hr/cases/'.$case->id.'/complete', ['version' => 2, 'completed_on' => '2026-10-24'])->assertSessionHasErrors('case');
    $this->patch('/hr/checklist/'.$item->id, ['status' => 'skipped', 'category' => 'documents', 'required' => false, 'version' => 1])->assertRedirect();
    $this->post('/hr/cases/'.$case->id.'/complete', ['version' => 3, 'completed_on' => '2026-10-24'])->assertRedirect();
    expect($item->fresh()->completed_on)->toBeNull()->and($case->fresh()->completed_on)->not->toBeNull();
});
it('keeps final-pay information separate from ordinary case access search and CSV', function () {
    $u = hrStaff();
    $u->givePermissionTo(['hr.final-pay.view', 'hr.final-pay.edit']);
    $employee = hrEmployee($u);
    $case = hrCase($u, $employee, ['kind' => 'offboarding', 'final_work_on' => '2026-10-24', 'final_pay_info' => 'Restricted final-pay handover notes.']);
    $u->revokePermissionTo(['hr.final-pay.view', 'hr.final-pay.edit']);
    $this->actingAs($u)->get('/hr/cases/'.$case->id)->assertInertia(fn (Assert $p) => $p->missing('record.final_pay_info')->where('canViewFinalPay', false));
    $this->get('/hr?section=cases&q=Restricted%20final-pay')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 0));
    expect($this->get('/hr/export?section=cases')->assertOk()->streamedContent())->not->toContain('Restricted final-pay')->not->toContain('final_pay_info');
    $this->patch('/hr/cases/'.$case->id, ['final_pay_info' => 'Unauthorized overwrite', 'version' => 1])->assertForbidden();
    $this->patch('/hr/cases/'.$case->id, ['name' => 'Prepare retained handover', 'version' => 1])->assertRedirect();
    expect($case->fresh()->final_pay_info)->toBe('Restricted final-pay handover notes.');
});
it('explicitly retains an inactive employee at offboarding without disabling a linked login', function () {
    $u = hrStaff();
    $employee = app(EmployeeReferences::class)->create($u, ['name' => 'Noa Cohen', 'user_id' => $u->id]);
    $case = hrCase($u, $employee, ['kind' => 'offboarding', 'final_work_on' => '2026-10-24']);
    $item = hrItem($u, $case, ['title' => 'Record manual access handover', 'category' => 'access']);
    $this->actingAs($u)->patch('/hr/checklist/'.$item->id, ['status' => 'done', 'completed_on' => '2026-10-24', 'category' => 'access', 'required' => true, 'version' => 1])->assertRedirect();
    $this->post('/hr/cases/'.$case->id.'/complete', ['version' => 3, 'completed_on' => '2026-10-24', 'deactivate_employee' => true, 'employee_version' => 99])->assertConflict();
    expect($case->fresh()->completed_on)->toBeNull()->and($employee->fresh()->active)->toBeTrue();
    $this->post('/hr/cases/'.$case->id.'/complete', ['version' => 3, 'completed_on' => '2026-10-24', 'deactivate_employee' => true, 'employee_version' => 1])->assertRedirect();
    expect($case->fresh()->completed_on)->not->toBeNull()->and($employee->fresh()->active)->toBeFalse()->and($u->fresh()->active)->toBeTrue()->and($employee->fresh()->getAttribute('user_id'))->toBe($u->id);
    $this->get('/hr/cases/'.$case->id)->assertInertia(fn (Assert $p) => $p->where('record.employee.active', false)->where('editable', false)->has('items', 1));
});
it('archives an abandoned case and incomplete tasks atomically without pretending work was done', function () {
    $u = hrStaff();
    $case = hrCase($u, hrEmployee($u));
    $item = hrItem($u, $case);
    $this->artisan('crm:remind')->assertSuccessful();
    expect(WorkReminder::where('module', 'hr')->count())->toBeGreaterThan(0);
    $this->actingAs($u)->post('/hr/cases/'.$case->id.'/archive', ['version' => 2])->assertRedirect();
    expect($item->fresh()->archived_at)->not->toBeNull()->and($item->fresh()->status)->toBe('not-started')->and($item->fresh()->completed_on)->toBeNull()->and($case->fresh()->completed_on)->toBeNull();
    expect(app(WorkReminders::class)->forUser($u))->toBe([]);
});
it('does not bypass assigned checklist scope when archiving a case', function () {
    $manager = hrStaff('Noa Cohen', true);
    $staff = hrStaff('Uri Erez');
    $case = hrCase($manager, hrEmployee($manager));
    $item = hrItem($manager, $case, ['owner_id' => $staff->id]);
    $manager->revokePermissionTo(['hr.cases.edit.all', 'hr.cases.view.all']);
    $this->actingAs($manager)->post('/hr/cases/'.$case->id.'/archive', ['version' => 2])->assertSessionHasErrors('case');
    expect($case->fresh()->archived_at)->toBeNull()->and($item->fresh()->archived_at)->toBeNull();
    $this->actingAs($staff)->get('/hr/checklist/'.$item->id)->assertInertia(fn (Assert $p) => $p->where('parentCase', null)->where('editable', true));
    $this->patch('/hr/checklist/'.$item->id, ['status' => 'done', 'category' => 'documents', 'required' => true, 'version' => 1])->assertRedirect();
});
it('scopes overview tables and formula-safe exports without exposing document or final-pay content', function () {
    $u = hrStaff();
    $other = hrStaff('Uri Cohen');
    $v = hrVacancy($u);
    $c = hrCandidate($u, $v, ['name' => '=Private candidate']);
    hrCandidate($other, hrVacancy($other), ['name' => 'Other private candidate']);
    $this->actingAs($u)->get('/hr?section=candidates&view=open&owner_id='.$u->id)->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('metrics.candidates', 1));
    $csv = $this->get('/hr/export?section=candidates&q=Private')->assertOk()->streamedContent();
    expect($csv)->toContain("'=Private candidate")->not->toContain('Other private candidate')->not->toContain('Private interview notes')->not->toContain('document_url');
    $u->revokePermissionTo('hr.recruitment.export');
    $this->get('/hr/export?section=candidates')->assertForbidden();
});
it('stops obsolete candidate and checklist reminders and denies disabled module reads and writes without deleting data', function () {
    $u = hrStaff();
    $candidate = hrCandidate($u, hrVacancy($u));
    $case = hrCase($u, hrEmployee($u));
    hrItem($u, $case);
    $this->artisan('crm:remind')->assertSuccessful();
    expect(count(app(WorkReminders::class)->forUser($u)))->toBe(3);
    $this->actingAs($u)->patch('/hr/candidates/'.$candidate->id, ['stage' => 'rejected', 'version' => 1])->assertRedirect();
    expect(count(app(WorkReminders::class)->forUser($u)))->toBe(2);
    $this->hrData['modules'] = [];
    file_put_contents($this->hrManifest, json_encode($this->hrData));
    $this->get('/hr')->assertNotFound();
    $this->get('/hr/export?section=cases')->assertNotFound();
    $this->patch('/hr/candidates/'.$candidate->id, ['stage' => 'new', 'version' => 2])->assertNotFound();
    expect(app(WorkReminders::class)->forUser($u))->toBe([])->and(Candidate::count())->toBe(1)->and(EmploymentCase::count())->toBe(1)->and(WorkItem::where('module', 'hr')->count())->toBe(1);
});

it('uses actual HR deadlines for overdue views and refreshes cached read permissions', function () {
    $u = hrStaff();
    $employee = hrEmployee($u);
    app(Records::class)->save($u, 'employees', hrProfile($u, $employee));
    $this->actingAs($u)->get('/hr?section=employees&view=overdue')->assertSessionHasErrors('view');
    $this->get('/hr?section=vacancies&view=overdue')->assertSessionHasErrors('view');
    $candidate = hrCandidate($u, hrVacancy($u), ['next_action_on' => now(Installation::settings()['timezone'])->subDay()->toDateString()]);
    $this->get('/hr?section=candidates&view=overdue')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1));
    $u->load('permissions');
    expect($u->can('hr.employment.view.own'))->toBeTrue();
    $u->fresh()->revokePermissionTo('hr.employment.view.own');
    expect(Access::available($u, 'employees'))->toBeFalse()->and(Access::query($u, 'employees')->count())->toBe(0);
});

it('retains configured HR labels on stable stage and checklist identifiers', function () {
    $u = hrStaff();
    $this->hrData['hr_candidate_stages'] = ['interview' => 'Conversation'];
    $this->hrData['hr_checklist_statuses'] = ['done' => 'Completed manually'];
    $this->hrData['hr_employment_statuses'] = ['active' => 'Employed'];
    file_put_contents($this->hrManifest, json_encode($this->hrData));
    $this->actingAs($u)->get('/hr?section=candidates')->assertInertia(fn (Assert $p) => $p->where('stages.interview', 'Conversation')->where('checklistStatuses.done', 'Completed manually')->where('employmentStatuses.active', 'Employed'));
    $this->artisan('crm:install')->assertSuccessful();
    expect(Records::stages()['interview'])->toBe('Conversation')->and((new HrModule)->workTypes()[0]->statuses['done'])->toBe('Completed manually');
});
