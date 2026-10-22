<?php

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\CustomFields;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverReminder;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Reminder;
use Awove\Core\Models\Setting;
use Awove\Core\Models\Status;
use Awove\Core\Models\Task;
use Awove\Core\Services\Leads;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
});
function employee(string $name = 'Employee'): User
{
    $u = User::factory()->create(['name' => $name, 'active' => true]);
    $u->assignRole('Employee');

    return $u;
}
function administrator(): User
{
    $u = employee('Admin');
    $u->syncRoles(['Administrator']);
    $u->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

    return $u;
}
function customer(User $u): Contact
{
    return Contact::create(['name' => 'Synthetic Person', 'created_by' => $u->id, 'search_key' => 'syntheticperson']);
}
function leadFor(User $u, array $extra = []): Lead
{
    return app(Leads::class)->create($u, [...['title' => 'Synthetic enquiry', 'contact_id' => customer($u)->id, 'owner_id' => $u->id, 'status_id' => 'new', 'priority' => 'normal', 'source' => 'Direct'], ...$extra]);
}
function taskFor(Lead $lead, User $u, array $extra = []): Task
{
    return Task::create([...['lead_id' => $lead->id, 'assignee_id' => $u->id, 'title' => 'Call customer', 'due_at' => now()->addHour(), 'remind_at' => now()->subMinute(), 'priority' => 'normal'], ...$extra]);
}
it('persists the entire scoped CRM workflow', function () {
    $u = employee();
    $this->actingAs($u)->post('/people/companies', ['name' => 'Fictional Studio'])->assertRedirect();
    $company = Company::first();
    $this->post('/people/contacts', ['name' => 'Fictional Person', 'company_id' => $company->id, 'email' => 'person@example.test'])->assertRedirect();
    $contact = Contact::first();
    $this->post('/leads', ['title' => 'New service enquiry', 'contact_id' => $contact->id, 'company_id' => $company->id, 'status_id' => 'new', 'priority' => 'normal', 'source' => 'Website'])->assertRedirect();
    $lead = Lead::first();
    expect($lead->owner_id)->toBe($u->id);
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'status_id' => 'contacted'])->assertRedirect();
    $this->post('/leads/'.$lead->id.'/notes', ['body' => 'Discussed the next step'])->assertRedirect();
    $this->post('/leads/'.$lead->id.'/tasks', ['title' => 'Follow up', 'assignee_id' => $u->id, 'due_at' => now()->addHour()->format('Y-m-d\TH:i:sP'), 'remind_at' => now()->subMinute()->format('Y-m-d\TH:i:sP'), 'priority' => 'high'])->assertRedirect();
    $this->artisan('crm:remind')->assertSuccessful();
    $task = Task::first();
    expect(Reminder::count())->toBe(1);
    $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('metrics.active', 1)->where('metrics.missing', 0));
    $this->post('/reminders/'.Reminder::first()->id.'/read')->assertRedirect();
    expect($task->fresh()->status)->toBe('open');
    $this->patch('/tasks/'.$task->id, ['version' => 1, 'status' => 'completed'])->assertRedirect();
    expect($task->fresh()->status)->toBe('completed');
    $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('metrics.missing', 1));
    expect(Activity::where('lead_id', $lead->id)->pluck('event')->all())->toContain('lead.created', 'lead.status_changed', 'note.created', 'task.created', 'task.completed');
});
it('denies own-only users direct URLs, forged edits, notes, tasks and extension requests', function () {
    $owner = employee();
    $other = employee();
    $lead = leadFor($owner);
    $task = taskFor($lead, $owner);
    $this->actingAs($other)->get('/leads/'.$lead->id)->assertForbidden();
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'status_id' => 'won'])->assertForbidden();
    $this->post('/leads/'.$lead->id.'/notes', ['body' => 'Forged'])->assertForbidden();
    $this->patch('/tasks/'.$task->id, ['version' => 1, 'status' => 'completed'])->assertForbidden();
    $this->post('/leads', ['title' => 'Forged relation', 'contact_id' => $lead->contact_id, 'status_id' => 'new'])->assertForbidden();
    $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->where('metrics.active', 0));
    $this->get('/people')->assertInertia(fn (Assert $p) => $p->has('contacts.data', 0));
    $this->get('/leads')->assertInertia(fn (Assert $p) => $p->has('leads.data', 0));
});
it('keeps all-read and own-edit separate including shared identity lead relations', function () {
    $a = employee();
    $b = employee();
    $b->givePermissionTo('leads.view.all');
    $lead = leadFor($a);
    leadFor($b, ['contact_id' => $lead->contact_id]);
    $this->actingAs($b)->get('/leads/'.$lead->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('editable', false));
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'title' => 'No'])->assertForbidden();
    expect(Access::identities($b, Contact::class)->whereKey($lead->contact_id)->exists())->toBeTrue();
    $b->revokePermissionTo('leads.view.all');
    expect(Access::leads($b)->count())->toBe(1);
});
it('rejects stale status and ownership versions without overwriting', function () {
    $admin = administrator();
    $lead = leadFor($admin);
    $this->actingAs($admin)->patch('/leads/'.$lead->id, ['version' => 1, 'status_id' => 'qualified'])->assertRedirect();
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'owner_id' => null])->assertConflict();
    expect($lead->fresh()->owner_id)->toBe($admin->id);
});
it('requires administrator MFA and prohibits public registration', function () {
    $u = employee();
    $u->syncRoles(['Administrator']);
    $this->actingAs($u)->get('/dashboard')->assertRedirect('/settings/security');
    $this->get('/business-settings')->assertRedirect('/settings/security');
    $this->post('/users', ['name' => 'No', 'email' => 'no@example.test', 'role' => 'Employee'])->assertRedirect('/settings/security');
    auth()->logout();
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});
it('revokes inactive users and prevents the last administrator deactivation', function () {
    $admin = administrator();
    $this->actingAs($admin)->patch('/users/'.$admin->id, ['active' => false])->assertUnprocessable();
    expect($admin->fresh()->active)->toBeTrue();
    $u = employee();
    $lead = leadFor($u);
    $task = taskFor($lead, $u);
    $this->patch('/users/'.$u->id, ['active' => false, 'reassign_to' => $admin->id])->assertRedirect();
    expect($u->fresh()->active)->toBeFalse()->and($lead->fresh()->owner_id)->toBe($admin->id)->and($task->fresh()->status)->toBe('cancelled');
    $this->actingAs($u)->get('/dashboard')->assertForbidden();
});
it('does not allow employees to manage settings or users', function () {
    $u = employee();
    $this->actingAs($u)->get('/business-settings')->assertForbidden();
    $this->post('/users', [])->assertForbidden();
    $this->patch('/business-settings', ['name' => 'No'])->assertForbidden();
});
it('deduplicates reminders and invalidates obsolete task versions', function () {
    $u = employee();
    $lead = leadFor($u);
    $task = taskFor($lead, $u);
    (new DeliverReminder($task->id, 1))->handle();
    (new DeliverReminder($task->id, 1))->handle();
    expect(Reminder::count())->toBe(1);
    $this->actingAs($u)->patch('/tasks/'.$task->id, ['version' => 1, 'status' => 'open', 'due_at' => now()->addDays(2)->format('Y-m-d\TH:i:sP'), 'remind_at' => now()->addDay()->format('Y-m-d\TH:i:sP')])->assertRedirect();
    (new DeliverReminder($task->id, 1))->handle();
    expect(Reminder::count())->toBe(1);
    $this->get('/reminders')->assertInertia(fn (Assert $p) => $p->has('reminders.data', 0));
    $task->refresh()->update(['remind_at' => now()->subMinute()]);
    (new DeliverReminder($task->id, 2))->handle();
    expect(Reminder::count())->toBe(2);
    $this->patch('/tasks/'.$task->id, ['version' => 2, 'status' => 'completed'])->assertRedirect();
    $this->get('/reminders')->assertInertia(fn (Assert $p) => $p->has('reminders.data', 0));
});
it('cancels pending work and suppresses reminders after ownership access changes', function () {
    $admin = administrator();
    $old = employee();
    $new = employee();
    $lead = leadFor($old);
    $task = taskFor($lead, $old);
    (new DeliverReminder($task->id, 1))->handle();
    app(Leads::class)->update($admin, $lead->id, ['owner_id' => $new->id, 'version' => 1]);
    expect($task->fresh()->status)->toBe('cancelled');
    $this->actingAs($old)->get('/reminders')->assertInertia(fn (Assert $p) => $p->has('reminders.data', 0));
    $this->post('/reminders/'.Reminder::first()->id.'/read')->assertNotFound();
});
it('never grants lead access from a task assignment', function () {
    $u = employee();
    $other = employee();
    $lead = leadFor($u);
    $this->actingAs($u)->post('/leads/'.$lead->id.'/tasks', ['title' => 'Invalid assignment', 'assignee_id' => $other->id, 'due_at' => now()->addHour()->format('Y-m-d\TH:i:sP'), 'remind_at' => now()->format('Y-m-d\TH:i:sP'), 'priority' => 'normal'])->assertForbidden();
    expect(Access::leads($other)->count())->toBe(0);
});
it('stores explicit offset instants across the daylight-saving repeated hour', function () {
    $u = employee();
    $lead = leadFor($u);
    $this->actingAs($u)->post('/leads/'.$lead->id.'/tasks', ['title' => 'DST follow-up', 'assignee_id' => $u->id, 'due_at' => '2026-10-25T03:30:00+02:00', 'remind_at' => '2026-10-25T03:30:00+03:00', 'priority' => 'normal'])->assertRedirect();
    $t = Task::first();
    expect($t->due_at->utc()->format('H:i'))->toBe('01:30')->and($t->remind_at->utc()->format('H:i'))->toBe('00:30');
});
it('validates typed fields and rejects executable or deployment-controlled settings', function () {
    expect(fn () => CustomFields::validate(['secret' => 'no']))->toThrow(ValidationException::class);
    config(['core.installation' => 'beta']);
    expect(CustomFields::validate(['service_type' => 'Maintenance']))->toBe(['service_type' => 'Maintenance']);
    expect(fn () => CustomFields::validate(['service_type' => 'Unknown']))->toThrow(ValidationException::class);
    $admin = administrator();
    $this->actingAs($admin)->patch('/business-settings', ['modules' => ['service-details']])->assertSessionHasErrors();
    $this->patch('/business-settings', ['color' => '<script>'])->assertSessionHasErrors();
    $this->patch('/business-settings', ['color' => '#25635b'])->assertSessionHasErrors('color');
    $this->patch('/business-settings', ['color' => '#333333'])->assertRedirect();
    expect(Installation::settings()['color'])->toBe('#333333');
});
it('retains customer configuration during repeated default installation', function () {
    Status::find('new')->update(['name' => 'Customer label', 'position' => 17]);
    Setting::create(['key' => 'name', 'value' => 'Customer brand']);
    $this->artisan('crm:install')->assertSuccessful();
    expect(Status::find('new')->name)->toBe('Customer label')->and(Status::find('new')->position)->toBe(17)->and(Installation::settings()['name'])->toBe('Customer brand');
});
it('archives records without deleting attribution or leaking reminders', function () {
    $admin = administrator();
    $lead = leadFor($admin);
    $t = taskFor($lead, $admin);
    (new DeliverReminder($t->id, 1))->handle();
    $this->actingAs($admin)->post('/leads/'.$lead->id.'/archive')->assertRedirect();
    $this->get('/leads')->assertInertia(fn (Assert $p) => $p->has('leads.data', 0));
    $this->get('/reminders')->assertInertia(fn (Assert $p) => $p->has('reminders.data', 0));
    $this->post('/leads/'.$lead->id.'/notes', ['body' => 'No'])->assertForbidden();
    expect(Activity::count())->toBe(2);
});
it('allows unassigned records only in the all-view queue', function () {
    $admin = administrator();
    $u = employee();
    $lead = leadFor($admin, ['owner_id' => null]);
    $this->actingAs($u)->get('/leads?view=unassigned')->assertForbidden();
    $this->actingAs($admin)->get('/leads?view=unassigned')->assertInertia(fn (Assert $p) => $p->has('leads.data', 1));
});
it('keeps the activity interface append-only', function () {
    $u = employee();
    leadFor($u);
    expect(fn () => Activity::first()->update(['event' => 'forged']))->toThrow(LogicException::class);
});

it('completes Fortify TOTP challenge and rejects inactive sign-in', function () {
    $u = administrator();
    $u->forceFill(['password' => 'A-local-test-password!', 'two_factor_recovery_codes' => encrypt(json_encode(['test-recovery-code']))])->save();
    $this->post('/login', ['email' => $u->email, 'password' => 'A-local-test-password!'])->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
    $otp = app(Google2FA::class)->getCurrentOtp(decrypt($u->two_factor_secret));
    $this->post('/two-factor-challenge', ['code' => $otp])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($u);
    $this->post('/logout');
    $u->forceFill(['active' => false])->save();
    $this->post('/login', ['email' => $u->email, 'password' => 'A-local-test-password!'])->assertSessionHasErrors();
    $this->assertGuest();
});
it('preserves intentionally changed role permissions when seeding defaults again', function () {
    $role = Role::findByName('Employee');
    $role->revokePermissionTo('leads.create');
    $this->artisan('crm:install');
    expect($role->fresh()->hasPermissionTo('leads.create'))->toBeFalse();
});
it('keeps deployment locks above administrator and user display preferences', function () {
    $u = employee();
    $u->forceFill(['display_preferences' => ['timezone' => 'Europe/London']])->save();
    Setting::create(['key' => 'timezone', 'value' => 'America/New_York']);
    expect(Installation::display($u)['timezone'])->toBe('Europe/London');
    config(['core.locks' => ['timezone']]);
    expect(Installation::display($u)['timezone'])->toBe('Asia/Nicosia');
});

it('bootstraps one administrator with an expiring single-use onboarding link', function () {
    Notification::fake();
    $this->artisan('crm:bootstrap-admin', ['email' => 'first-admin@example.test'])->assertSuccessful();
    $admin = User::where('email', 'first-admin@example.test')->firstOrFail();
    expect($admin->hasRole('Administrator'))->toBeTrue()->and($admin->email_verified_at)->not->toBeNull();
    $token = null;
    Notification::assertSentTo($admin, ResetPassword::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });
    $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => 'Local-onboarding-password!', 'password_confirmation' => 'Local-onboarding-password!'])->assertRedirect('/login');
    $this->post('/reset-password', ['token' => $token, 'email' => $admin->email, 'password' => 'Another-local-password!', 'password_confirmation' => 'Another-local-password!'])->assertSessionHasErrors('email');
    $this->artisan('crm:bootstrap-admin', ['email' => 'second-admin@example.test'])->assertFailed();
    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/settings/security');
    expect(config('auth.passwords.users.expire'))->toBe(60);
});

it('allows an enrolled administrator to invite an employee without a known password', function () {
    Notification::fake();
    $admin = administrator();
    $this->actingAs($admin)->post('/users', ['name' => 'Invited teammate', 'email' => 'teammate@example.test', 'role' => 'Employee'])->assertRedirect();
    $user = User::where('email', 'teammate@example.test')->firstOrFail();
    expect($user->hasRole('Employee'))->toBeTrue()->and($user->active)->toBeTrue();
    Notification::assertSentTo($user, ResetPassword::class);
});

it('validates all five supported custom-field types and rejects invalid values', function () {
    $manifest = Installation::manifest();
    $manifest['custom_fields'] = ['text' => ['type' => 'text', 'label' => 'Text'], 'number' => ['type' => 'number', 'label' => 'Number'], 'date' => ['type' => 'date', 'label' => 'Date'], 'select' => ['type' => 'select', 'label' => 'Select', 'options' => ['A', 'B']], 'boolean' => ['type' => 'boolean', 'label' => 'Boolean']];
    $file = tempnam(sys_get_temp_dir(), 'base-crm-fields-');
    file_put_contents($file, json_encode($manifest));
    config(['core.manifest' => $file]);
    try {
        expect(CustomFields::validate(['text' => 'Value', 'number' => 12.5, 'date' => '2026-10-22', 'select' => 'B', 'boolean' => true]))->toHaveCount(5);
        foreach ([['number' => 'not a number'], ['date' => 'tomorrow'], ['select' => 'C'], ['boolean' => 'yes'], ['text' => ['array']]] as $invalid) {
            expect(fn () => CustomFields::validate($invalid))->toThrow(ValidationException::class);
        }
    } finally {
        unlink($file);
    }
});

it('warns about normalized phone duplicates without enforcing shared-number uniqueness', function () {
    $u = employee();
    $this->actingAs($u)->post('/people/contacts', ['name' => 'Person One', 'phone' => '+357 99 123 456'])->assertRedirect();
    $this->post('/people/contacts', ['name' => 'Person Two', 'phone' => '+357-99-123-456'])->assertSessionHasErrors('name');
    $this->post('/people/contacts', ['name' => 'Person Two', 'phone' => '+357-99-123-456', 'accept_duplicate' => true])->assertRedirect();
    expect(Contact::count())->toBe(2);
});
