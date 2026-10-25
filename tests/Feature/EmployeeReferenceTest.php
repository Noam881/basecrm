<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Models\EmployeeReference;
use Awove\Core\Models\EmployeeReferenceHistory;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Services\Identities;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->employeeManifest = tempnam(sys_get_temp_dir(), 'base-crm-employees-');
    $manifest = Installation::manifest();
    $manifest['modules'] = [];
    $manifest['capabilities']['sales'] = false;
    file_put_contents($this->employeeManifest, json_encode($manifest));
    config(['core.manifest' => $this->employeeManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    unlink($this->employeeManifest);
});
function employeeReferenceStaff(bool $manage = true, string $name = 'Noa Cohen'): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Employee');
    $user->givePermissionTo(['employees.references.view', ...($manage ? ['employees.references.manage'] : [])]);

    return $user;
}
function employeeReferenceStatus(callable $action, int $status): void
{
    try {
        $action();
        test()->fail('Expected an HTTP access or concurrency error.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($status);
    }
}

it('creates a stable nonlogin reference without any business module contacts users or name merging', function () {
    $actor = employeeReferenceStaff();
    $service = app(EmployeeReferences::class);
    $contacts = Contact::count();
    $users = User::count();
    $one = $service->create($actor, ['name' => ' Shira Levi ']);
    $two = $service->create($actor, ['name' => 'Shira Levi']);
    expect($one->toArray())->toEqualCanonicalizing(['name' => 'Shira Levi', 'active' => true, 'id' => $one->id])
        ->and($one->version)->toBe(1)->and($one->getAttribute('contact_id'))->toBeNull()->and($one->getAttribute('user_id'))->toBeNull()
        ->and($one->id)->not->toBe($two->id)->and(Contact::count())->toBe($contacts)->and(User::count())->toBe($users);
    $this->actingAs($actor)->get('/people/employees')->assertOk();
    $this->get('/leads')->assertNotFound();
});

it('never auto grants reference permissions and requires explicit view even with manage or HR-style access', function () {
    $actor = User::factory()->create();
    $actor->assignRole('Administrator');
    expect($actor->can('employees.references.view'))->toBeFalse()->and($actor->can('employees.references.manage'))->toBeFalse();
    $this->actingAs($actor)->get('/people/employees')->assertForbidden();
    $actor->givePermissionTo('employees.references.manage');
    employeeReferenceStatus(fn () => app(EmployeeReferences::class)->create($actor, ['name' => 'Uri Erez']), 403);
    expect(app(EmployeeReferences::class)->options($actor))->toBe([]);
});

it('retains inactive references while each consuming domain chooses whether they are selectable', function () {
    $actor = employeeReferenceStaff();
    $service = app(EmployeeReferences::class);
    $record = $service->create($actor, ['name' => 'Yael Shalev']);
    $service->setActive($actor, $record->id, 1, false);
    expect($service->options($actor))->toBe([])->and($service->options($actor, true))->toHaveCount(1)
        ->and($service->find($actor, $record->id)->active)->toBeFalse()->and($service->summary($actor, $record->id))->toBe(['id' => $record->id, 'name' => 'Yael Shalev', 'active' => false]);
    expect(fn () => $service->assertSelectable($actor, $record->id))->toThrow(ValidationException::class);
    expect($service->assertSelectable($actor, $record->id, allowInactive: true)->id)->toBe($record->id);
    $service->setActive($actor, $record->id, 2, true);
    expect($service->options($actor))->toHaveCount(1)->and(EmployeeReference::count())->toBe(1);
});

it('rejects stale edits and retains attributed immutable history instead of deleting references', function () {
    $actor = employeeReferenceStaff();
    $service = app(EmployeeReferences::class);
    $record = $service->create($actor, ['name' => 'Eitan Erez']);
    $service->update($actor, $record->id, ['version' => 1, 'name' => 'Eitan Ben Erez']);
    employeeReferenceStatus(fn () => $service->setActive($actor, $record->id, 1, false), 409);
    expect($record->fresh()->name)->toBe('Eitan Ben Erez')->and($record->fresh()->active)->toBeTrue()->and($service->history($actor, $record->id))->toHaveCount(2);
    expect($service->history($actor, $record->id)[0])->toMatchArray(['version' => 2, 'changed_fields' => ['name'], 'actor' => ['id' => $actor->id, 'name' => $actor->name]]);
    expect(fn () => $record->delete())->toThrow(LogicException::class);
    expect(fn () => EmployeeReferenceHistory::first()->update(['event' => 'forged']))->toThrow(LogicException::class);
});

it('reloads activity and reference grants rather than trusting stale caller objects', function () {
    $actor = employeeReferenceStaff();
    $service = app(EmployeeReferences::class);
    $record = $service->create($actor, ['name' => 'Lior Erez']);
    $actor->load('permissions');
    $actor->fresh()->revokePermissionTo('employees.references.view');
    expect($service->summary($actor, $record->id))->toBeNull()->and($service->query($actor)->count())->toBe(0);
    employeeReferenceStatus(fn () => $service->update($actor, $record->id, ['version' => 1, 'name' => 'Forbidden']), 403);
    $actor->fresh()->givePermissionTo('employees.references.view');
    $actor->fresh()->forceFill(['active' => false])->save();
    expect($service->options($actor))->toBe([]);
    employeeReferenceStatus(fn () => $service->find($actor, $record->id), 403);
    expect($record->fresh()->version)->toBe(1);
});

it('sets explicit scoped links without granting contact or account access and enforces uniqueness', function () {
    $actor = employeeReferenceStaff();
    $other = employeeReferenceStaff(name: 'Uri Shalev');
    $contact = app(Identities::class)->create($actor, 'contacts', ['name' => 'Shira Levi', 'email' => 'shira@example.test']);
    $hidden = app(Identities::class)->create($other, 'contacts', ['name' => 'Private contact', 'email' => 'private@example.test']);
    $service = app(EmployeeReferences::class);
    employeeReferenceStatus(fn () => $service->create($actor, ['name' => 'Hidden link', 'contact_id' => $hidden->id]), 403);
    employeeReferenceStatus(fn () => $service->create($actor, ['name' => 'Other login', 'user_id' => $other->id]), 403);
    $record = $service->create($actor, ['name' => 'Shira Levi', 'contact_id' => $contact->id, 'user_id' => $actor->id]);
    expect($service->describe($actor, $record))->toMatchArray(['contact_id' => $contact->id, 'user_id' => $actor->id]);
    expect(fn () => $service->create($actor, ['name' => 'Duplicate explicit contact', 'contact_id' => $contact->id]))->toThrow(ValidationException::class);
    expect(fn () => $service->create($actor, ['name' => 'Duplicate explicit login', 'user_id' => $actor->id]))->toThrow(ValidationException::class);
    expect(EmployeeReference::count())->toBe(1)->and($actor->fresh()->can('users.manage'))->toBeFalse()->and($actor->fresh()->can('identities.view.all'))->toBeFalse();
    $service->update($actor, $record->id, ['version' => 1, 'contact_action' => 'clear', 'user_action' => 'clear']);
    expect($record->fresh()->getAttribute('contact_id'))->toBeNull()->and($record->fresh()->getAttribute('user_id'))->toBeNull();
});

it('redacts retained hidden links in details history search exports and ordinary redacted saves', function () {
    $actor = employeeReferenceStaff();
    $other = employeeReferenceStaff(name: 'Uri Ben David');
    $actor->givePermissionTo(['users.manage', 'exports.view']);
    $contact = app(Identities::class)->create($actor, 'contacts', ['name' => 'Restricted linked person', 'email' => 'secret@example.test']);
    $service = app(EmployeeReferences::class);
    $record = $service->create($actor, ['name' => 'Aviv Erez', 'contact_id' => $contact->id, 'user_id' => $other->id]);
    $contact->update(['created_by' => $other->id]);
    $actor->revokePermissionTo('users.manage');
    $view = $service->describe($actor, $record);
    expect($view)->toMatchArray(['contact_id' => null, 'user_id' => null, 'contact' => null, 'account' => null, 'has_hidden_contact' => true, 'has_hidden_account' => true]);
    $history = $service->history($actor, $record->id);
    expect($history[0]['links']['contact_id']['to'])->toBeNull()->and($history[0]['links']['user_id']['to'])->toBeNull();
    $service->update($actor, $record->id, ['version' => 1, 'name' => 'Aviv Ben Erez', 'contact_id' => null, 'user_id' => null]);
    expect($record->fresh()->getAttribute('contact_id'))->toBe($contact->id)->and($record->fresh()->getAttribute('user_id'))->toBe($other->id);
    employeeReferenceStatus(fn () => $service->update($actor, $record->id, ['version' => 2, 'contact_action' => 'clear']), 403);
    $this->actingAs($actor)->get('/people/employees?q=Restricted%20linked%20person')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 0));
    $response = $this->get('/people/employees/export')->assertOk()->streamedContent();
    expect($response)->toContain('Aviv Ben Erez')->not->toContain('Restricted linked person', 'secret@example.test', 'Uri Ben David');
});

it('authorizes management exports preferences and invalid advanced fields server side', function () {
    $manager = employeeReferenceStaff();
    $viewer = employeeReferenceStaff(false);
    $service = app(EmployeeReferences::class);
    $record = $service->create($manager, ['name' => 'Miriam Cohen']);
    $this->actingAs($viewer)->post('/people/employees', ['name' => 'Forbidden'])->assertForbidden();
    $this->patch('/people/employees/'.$record->id, ['name' => 'Forbidden', 'version' => 1])->assertForbidden();
    $this->patch('/people/employees/'.$record->id.'/active', ['active' => false, 'version' => 1])->assertForbidden();
    $this->get('/people/employees/export')->assertForbidden();
    $this->getJson('/people/employees?sort=email')->assertUnprocessable();
    $this->getJson('/people/employees?filters[0][id]=name&filters[0][op]=gt&filters[0][value]=M')->assertUnprocessable();
    $this->post('/preferences', ['table' => 'employees', 'columns' => ['name', 'active']])->assertRedirect();
    expect($viewer->fresh()->display_preferences['tables']['employees']['columns'])->toBe(['name', 'active']);
    $viewer->revokePermissionTo('employees.references.view');
    $this->get('/people/employees/'.$record->id)->assertForbidden();
    $this->post('/preferences', ['table' => 'employees', 'columns' => ['name']])->assertForbidden();
});

it('exports formula safe reference values with exactly the current table query and active filter', function () {
    $actor = employeeReferenceStaff();
    $actor->givePermissionTo('exports.view');
    $service = app(EmployeeReferences::class);
    $service->create($actor, ['name' => '=HYPERLINK("https://example.test")']);
    $service->create($actor, ['name' => 'An inactive reference', 'active' => false]);
    $service->create($actor, ['name' => 'Another active reference']);
    $this->actingAs($actor)->get('/people/employees?active=1&q=HYPERLINK')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('table.page.data.0.cells.active', true));
    $csv = $this->get('/people/employees/export?active=1&q=HYPERLINK')->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->not->toContain('An inactive reference', 'Another active reference');
});

it('does not accept protected attribution or private employee information into the shared store', function () {
    $actor = employeeReferenceStaff();
    $service = app(EmployeeReferences::class);
    expect(fn () => $service->create($actor, ['name' => 'Noa Cohen', 'created_by' => 100]))->toThrow(ValidationException::class);
    $record = $service->create($actor, ['name' => 'Noa Cohen', 'email' => 'private@example.test', 'salary' => '12345', 'job_title' => 'Private role']);
    expect(array_keys($record->toArray()))->toEqualCanonicalizing(['id', 'name', 'active']);
    expect(json_encode($service->describe($actor, $record)))->not->toContain('private@example.test', 'Private role', '12345');
    expect(DB::table('crm_employee_references')->where('id', $record->id)->first())->not->toHaveProperty('email');
});
