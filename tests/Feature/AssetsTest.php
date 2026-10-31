<?php

use App\Models\User;
use Awove\Assets\Assignments;
use Awove\Assets\Maintenance;
use Awove\Assets\Models\AssetHistory;
use Awove\Assets\Models\MaintenanceCompletion;
use Awove\Assets\Models\MaintenanceCost;
use Awove\Assets\Records;
use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Services\WorkReminders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->assetsManifest = tempnam(sys_get_temp_dir(), 'crm-assets-');
    $this->assetsSettings = Installation::manifest();
    $this->assetsSettings['modules'] = ['assets'];
    $this->assetsSettings['capabilities'] = ['sales' => false];
    if (isset(app(Modules::class)->installed()['finance'])) {
        $this->assetsSettings['capabilities'] = [...$this->assetsSettings['capabilities'], 'finance:collections' => false, 'finance:expenses' => false, 'finance:payroll' => false];
    }
    $this->assetsSettings['timezone'] = 'Asia/Jerusalem';
    file_put_contents($this->assetsManifest, json_encode($this->assetsSettings));
    config(['core.manifest' => $this->assetsManifest]);
    $this->artisan('crm:install')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-25T12:00:00+02:00'));
});
afterEach(function () {
    unlink($this->assetsManifest);
});

function assetStaff(bool $all = false, bool $documents = true, bool $costs = true): User
{
    $user = User::factory()->create(['name' => 'Shira Cohen', 'active' => true]);
    $user->assignRole('Employee');
    $permissions = ['assets.view.own', 'assets.create', 'assets.edit.own', 'assets.archive', 'assets.export', 'employees.references.view', 'employees.references.manage', 'identities.classifications.view', 'identities.classifications.manage'];
    if ($all) {
        $permissions = [...$permissions, 'assets.view.all', 'assets.edit.all', 'assets.assign'];
    }
    if ($documents) {
        $permissions = [...$permissions, 'assets.documents.view', 'assets.documents.manage'];
    }
    if ($costs) {
        $permissions = [...$permissions, 'assets.costs.view', 'assets.costs.manage'];
    }
    $user->givePermissionTo($permissions);

    return $user;
}
function assetOperator(): User
{
    $user = assetStaff();
    $user->givePermissionTo('assets.assign');

    return $user;
}
function assetData(User $user, array $changes = []): array
{
    return [...['code' => 'EQ-101', 'name' => 'Office projector', 'type' => 'Equipment', 'location' => 'Tel Aviv office', 'owner_id' => $user->id, 'notes' => 'Fictional shared equipment.', 'purchased_on' => '2026-02-01', 'serial_number' => 'PROJECTOR-101', 'status' => 'available'], ...$changes];
}
function assetCreate(User $user, array $changes = [])
{
    return app(Records::class)->save($user, assetData($user, $changes));
}
function assetEmployee(User $user, bool $active = true)
{
    return app(EmployeeReferences::class)->create($user, ['name' => 'Yael Shalev', 'active' => $active]);
}
function assetRelationship(User $user, string $role = 'customer')
{
    $model = $role === 'supplier' ? Company::class : Contact::class;
    $identity = $model::create(['name' => $role === 'supplier' ? 'Carmel Supply' : 'Noam Levi', 'email' => (string) Str::uuid().'@example.test', 'phone' => '+1 202-555-0100', 'search_key' => $role, 'created_by' => $user->id]);

    return app(IdentityRelationships::class)->create($user, [($role === 'supplier' ? 'company_id' : 'contact_id') => $identity->id, 'role' => $role, 'responsible_id' => $user->id]);
}
function assetAssignment($asset, array $changes = []): array
{
    return [...['asset_version' => $asset->fresh()->version, 'target_type' => 'location', 'location' => 'Jerusalem office', 'assigned_on' => '2026-10-24', 'expected_return_on' => '2026-10-25', 'notes' => 'Agreed temporary use.'], ...$changes];
}
function assetMaintenance(User $user, $asset, array $changes = []): array
{
    return [...['asset_version' => $asset->fresh()->version, 'action' => 'Check projector lamp', 'description' => 'Inspect the fictional equipment.', 'owner_id' => $user->id, 'supplier_relationship_id' => null, 'due_on' => '2026-10-25', 'status' => 'planned'], ...$changes];
}
function assetFinanceSnapshot(): array
{
    $snapshot = [];
    foreach (['finance_records', 'finance_payments'] as $table) {
        $snapshot[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
    }

    return $snapshot;
}
function assetReloadSettings($test): void
{
    file_put_contents($test->assetsManifest, json_encode($test->assetsSettings));
}

it('persists a generic asset register independently with stable fields exact IDs search and retained edits', function () {
    $user = assetStaff();
    $this->actingAs($user)->post('/assets/register', assetData($user))->assertRedirect();
    $id = DB::table('assets_register')->sole()->id;
    $this->get('/assets/register/'.$id)->assertInertia(fn (Assert $page) => $page->where('record.code', 'EQ-101')->where('record.name', 'Office projector')->where('record.location', 'Tel Aviv office')->where('record.serial_number', 'PROJECTOR-101')->where('record.purchased_on', '2026-02-01')->where('record.status', 'available'));
    $this->patch('/assets/register/'.$id, [...assetData($user), 'version' => 1, 'name' => 'Meeting projector'])->assertRedirect();
    $this->get('/assets?section=register&q=PROJECTOR-101')->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1)->where('table.page.data.0.name', 'Meeting projector'));
    foreach (['sales', 'hr', 'operations', 'finance', 'knowledge', 'marketing'] as $module) {
        expect(app(Modules::class)->enabled($module))->toBeFalse();
    }
    expect(DB::table('assets_history')->where('asset_id', $id)->count())->toBe(2);
});

it('validates unique codes dates and stable configurable status labels without overwriting records', function () {
    $user = assetStaff();
    $asset = assetCreate($user);
    $this->actingAs($user)->postJson('/assets/register', assetData($user))->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/assets/register', assetData($user, ['code' => 'EQ-102', 'name' => '  ']))->assertUnprocessable();
    $this->postJson('/assets/register', assetData($user, ['code' => 'EQ-102', 'purchased_on' => '2026-02-30']))->assertUnprocessable();
    $this->assetsSettings['assets_statuses'] = ['available' => 'Ready for use'];
    assetReloadSettings($this);
    $this->artisan('crm:install')->assertSuccessful();
    expect($asset->fresh()->code)->toBe('EQ-101')->and($asset->fresh()->status)->toBe('available');
    $this->get('/assets/register/'.$asset->id)->assertInertia(fn (Assert $page) => $page->where('statuses.available', 'Ready for use'));
});

it('enforces own all-only assignment and direct mutation scopes without granting asset users broader identity access', function () {
    $owner = assetStaff();
    $viewer = assetStaff();
    $asset = assetCreate($owner);
    $this->actingAs($viewer)->get('/assets/register/'.$asset->id)->assertNotFound();
    $this->patchJson('/assets/register/'.$asset->id, [...assetData($viewer), 'version' => 1])->assertNotFound();
    $this->postJson('/assets/register', assetData($viewer, ['owner_id' => $owner->id, 'code' => 'EQ-102']))->assertForbidden();
    $this->postJson('/assets/register', assetData($viewer, ['owner_id' => null, 'code' => 'EQ-102']))->assertForbidden();
    $viewer->revokePermissionTo('assets.view.own');
    $viewer->givePermissionTo('assets.view.all');
    $this->get('/assets?section=register')->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1));
    $this->get('/assets/register/'.$asset->id)->assertOk();
    $this->patchJson('/assets/register/'.$asset->id, [...assetData($owner), 'version' => 1])->assertForbidden();
    $viewer->givePermissionTo('assets.edit.all');
    $this->patchJson('/assets/register/'.$asset->id, [...assetData($owner), 'version' => 1, 'notes' => 'Reviewed fictional equipment.'])->assertRedirect();
    expect($owner->fresh()->getAllPermissions()->pluck('name')->contains('assets.view.all'))->toBeFalse();
});

it('rejects stale edits and assignment replays while retaining a single active assignment and return history', function () {
    $user = assetOperator();
    $asset = assetCreate($user);
    $this->actingAs($user)->patchJson('/assets/register/'.$asset->id, [...assetData($user), 'version' => 2])->assertConflict();
    $data = assetAssignment($asset);
    $assignment = app(Assignments::class)->create($user, $asset->id, $data);
    expect($asset->fresh()->status)->toBe('in-use')->and(DB::table('assets_assignments')->count())->toBe(1);
    $this->postJson('/assets/register/'.$asset->id.'/assignments', $data)->assertConflict();
    $this->postJson('/assets/register/'.$asset->id.'/assignments', assetAssignment($asset))->assertUnprocessable();
    $this->patchJson('/assets/register/'.$asset->id, [...assetData($user), 'version' => $asset->fresh()->version, 'status' => 'available'])->assertUnprocessable();
    $returned = ['asset_version' => $asset->fresh()->version, 'version' => $assignment->version, 'returned_on' => '2026-10-25', 'return_condition' => 'Good condition'];
    $this->postJson('/assets/assignments/'.$assignment->id.'/return', [...$returned, 'returned_on' => '2026-10-23'])->assertUnprocessable();
    $this->post('/assets/assignments/'.$assignment->id.'/return', $returned)->assertRedirect();
    $this->postJson('/assets/assignments/'.$assignment->id.'/return', $returned)->assertConflict();
    expect($asset->fresh()->status)->toBe('available')->and(DB::table('assets_assignments')->sole()->return_condition)->toBe('Good condition');
    app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['location' => 'Haifa office']));
    expect(DB::table('assets_assignments')->count())->toBe(2)->and(DB::table('assets_assignments')->whereNull('returned_on')->count())->toBe(1);
});

it('uses active nonlogin employee references without creating a contact and retains inactive historical assignees', function () {
    $user = assetOperator();
    $asset = assetCreate($user);
    $employee = assetEmployee($user);
    $contacts = Contact::count();
    $users = User::count();
    $assignment = app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['target_type' => 'employee', 'employee_reference_id' => $employee->id, 'location' => null]));
    app(EmployeeReferences::class)->setActive($user, $employee->id, $employee->version, false);
    expect(Contact::count())->toBe($contacts)->and(User::count())->toBe($users)->and($employee->fresh()->active)->toBeFalse();
    $this->actingAs($user)->get('/assets/register/'.$asset->id)->assertOk();
    $this->post('/assets/assignments/'.$assignment->id.'/return', ['asset_version' => $asset->fresh()->version, 'version' => $assignment->version, 'returned_on' => '2026-10-25', 'return_condition' => 'Checked'])->assertRedirect();
    $this->postJson('/assets/register/'.$asset->id.'/assignments', assetAssignment($asset, ['target_type' => 'employee', 'employee_reference_id' => $employee->id, 'location' => null]))->assertUnprocessable();
    expect(DB::table('assets_assignments')->sole()->employee_reference_id)->toBe($employee->id);
});

it('accepts scoped customer and location assignments without Sales or Operations and rejects hidden targets', function () {
    $user = assetOperator();
    $other = assetStaff();
    $asset = assetCreate($user);
    $customer = assetRelationship($user);
    $hidden = assetRelationship($other);
    $this->actingAs($user)->postJson('/assets/register/'.$asset->id.'/assignments', assetAssignment($asset, ['target_type' => 'customer', 'customer_relationship_id' => $hidden->id, 'location' => null]))->assertForbidden();
    $assignment = app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['target_type' => 'customer', 'customer_relationship_id' => $customer->id, 'location' => null]));
    expect($assignment->customer_relationship_id)->toBe($customer->id);
    app(Assignments::class)->return($user, $assignment->id, ['asset_version' => $asset->fresh()->version, 'version' => $assignment->version, 'returned_on' => '2026-10-25', 'return_condition' => 'Returned intact']);
    $location = app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['target_type' => 'location', 'location' => 'Jerusalem office']));
    expect($location->location)->toBe('Jerusalem office')->and(app(Modules::class)->enabled('sales'))->toBeFalse()->and(app(Modules::class)->enabled('operations'))->toBeFalse();
});

it('keeps physical assignment and maintenance states consistent while preserving a manually scheduled completion', function () {
    $user = assetOperator();
    $asset = assetCreate($user, ['status' => 'in-use']);
    $assignment = app(Assignments::class)->create($user, $asset->id, assetAssignment($asset));
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset, ['status' => 'in-progress']));
    expect($asset->fresh()->status)->toBe('under-maintenance');
    $this->actingAs($user)->postJson('/assets/register/'.$asset->id.'/archive', ['version' => $asset->fresh()->version])->assertUnprocessable();
    $this->postJson('/assets/maintenance/'.$work->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available'])->assertUnprocessable();
    $this->post('/assets/assignments/'.$assignment->id.'/return', ['asset_version' => $asset->fresh()->version, 'version' => $assignment->version, 'returned_on' => '2026-10-25', 'return_condition' => 'Sent to maintenance'])->assertRedirect();
    expect($asset->fresh()->status)->toBe('under-maintenance');
    $this->post('/assets/maintenance/'.$work->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available', 'next_due_on' => '2026-11-25'])->assertRedirect();
    expect($asset->fresh()->status)->toBe('available')->and(DB::table('assets_maintenance')->sole()->status)->toBe('completed')->and(DB::table('assets_maintenance')->count())->toBe(1)->and(DB::table('assets_maintenance_completions')->sole()->next_due_on)->toBe('2026-11-25');
});

it('records exact optional maintenance cost without creating Finance work and protects immutable completion history', function () {
    $user = assetStaff();
    $asset = assetCreate($user);
    $supplier = assetRelationship($user, 'supplier');
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset, ['supplier_relationship_id' => $supplier->id]));
    $financeBefore = assetFinanceSnapshot();
    $this->actingAs($user)->post('/assets/maintenance/'.$work->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available', 'cost' => '175.35', 'next_due_on' => '2026-11-25'])->assertRedirect();
    $snapshot = DB::table('assets_maintenance_completions')->sole();
    expect(DB::table('assets_maintenance_costs')->sole()->amount)->toBe('175.35')->and(DB::table('assets_maintenance_costs')->sole()->currency)->toBe(Currency::current());
    expect(assetFinanceSnapshot())->toBe($financeBefore);
    $this->postJson('/assets/maintenance/'.$work->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available', 'cost' => '175.35'])->assertConflict();
    $this->patch('/assets/maintenance/'.$work->id, ['asset_version' => $asset->fresh()->version, 'version' => $work->fresh()->version, 'next_due_on' => '2026-12-25'])->assertRedirect();
    expect(DB::table('assets_maintenance_completions')->sole())->toEqual($snapshot)->and(DB::table('assets_maintenance')->sole()->next_due_on)->toBe('2026-12-25')->and(DB::table('assets_maintenance_costs')->count())->toBe(1);
});

it('redacts employee customer and supplier relationships after access loss without erasing retained links', function () {
    $user = assetOperator();
    $asset = assetCreate($user);
    $employee = assetEmployee($user);
    $assignment = app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['target_type' => 'employee', 'employee_reference_id' => $employee->id, 'location' => null]));
    $user->revokePermissionTo('employees.references.view');
    $response = $this->actingAs($user)->get('/assets/register/'.$asset->id)->assertOk();
    expect(json_encode($response->inertiaProps()))->not->toContain('Yael Shalev');
    $this->patch('/assets/register/'.$asset->id, [...assetData($user), 'status' => 'in-use', 'version' => $asset->fresh()->version, 'notes' => 'Retain the historical assignee.'])->assertRedirect();
    expect(DB::table('assets_assignments')->sole()->employee_reference_id)->toBe($employee->id);
    app(Assignments::class)->return($user, $assignment->id, ['asset_version' => $asset->fresh()->version, 'version' => $assignment->version, 'returned_on' => '2026-10-25', 'return_condition' => 'Checked']);
    $supplier = assetRelationship($user, 'supplier');
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset, ['supplier_relationship_id' => $supplier->id]));
    $user->revokePermissionTo(['identities.classifications.view', 'identities.classifications.manage']);
    $response = $this->get('/assets/maintenance/'.$work->id)->assertOk();
    expect(json_encode($response->inertiaProps()))->not->toContain('Carmel Supply');
    $this->patch('/assets/maintenance/'.$work->id, ['asset_version' => $asset->fresh()->version, 'version' => $work->fresh()->version, 'action' => 'Inspect projector', 'supplier_relationship_id' => null])->assertRedirect();
    expect(DB::table('assets_maintenance')->sole()->supplier_relationship_id)->toBe($supplier->id);
});

it('requires maintenance staff to already view the specific asset and never grants its assignees access', function () {
    $owner = assetStaff(true);
    $other = assetStaff();
    $asset = assetCreate($owner);
    $employee = assetEmployee($owner);
    $this->actingAs($owner)->postJson('/assets/register/'.$asset->id.'/maintenance', assetMaintenance($owner, $asset, ['owner_id' => $other->id]))->assertUnprocessable();
    expect($other->can('assets.view.all'))->toBeFalse()->and(DB::table('assets_maintenance')->count())->toBe(0);
    $other->givePermissionTo('assets.view.all');
    $work = app(Maintenance::class)->save($owner, $asset->id, assetMaintenance($owner, $asset, ['owner_id' => $other->id]));
    expect($work->owner_id)->toBe($other->id);
    $assignment = app(Assignments::class)->create($owner, $asset->id, assetAssignment($asset, ['target_type' => 'employee', 'employee_reference_id' => $employee->id, 'location' => null]));
    expect($assignment->employee_reference_id)->toBe($employee->id)->and($employee->fresh()->user_id)->toBeNull();
    $other->revokePermissionTo('assets.view.all');
    $this->actingAs($other)->get('/assets/maintenance/'.$work->id)->assertNotFound();
    $this->get('/assets/register/'.$asset->id)->assertNotFound();
});

it('limits maintenance costs documents and exports independently of ordinary asset visibility', function () {
    $user = assetStaff();
    $asset = assetCreate($user);
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset));
    $this->actingAs($user)->post('/assets/maintenance/'.$work->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available', 'cost' => '175.35'])->assertRedirect();
    $user->revokePermissionTo('assets.costs.view');
    $response = $this->get('/assets/maintenance/'.$work->id)->assertOk();
    expect(json_encode($response->inertiaProps()))->not->toContain('175.35');
    $csv = $this->get('/assets/export?section=maintenance')->assertOk()->streamedContent();
    expect($csv)->not->toContain('175.35');
    $this->getJson('/assets?'.http_build_query(['section' => 'maintenance', 'filters' => [['id' => 'cost', 'op' => 'eq', 'value' => '175.35']]]))->assertUnprocessable();
    $user->givePermissionTo('assets.costs.view');
    $user->revokePermissionTo('assets.costs.manage');
    $next = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset, ['action' => 'Check display']));
    $this->postJson('/assets/maintenance/'.$next->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $next->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available', 'cost' => '1.00'])->assertForbidden();
    expect(DB::table('assets_maintenance_costs')->count())->toBe(1);
});

it('rejects imprecise costs and protects retained currency snapshots while allowing cost-free physical completion', function () {
    $user = assetStaff();
    $asset = assetCreate($user);
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset));
    $base = ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available'];
    $this->actingAs($user);
    foreach (['0.001', '-1.00', 0.1, '1000000000000.00'] as $cost) {
        $this->postJson('/assets/maintenance/'.$work->id.'/complete', [...$base, 'cost' => $cost])->assertUnprocessable();
    }
    expect(DB::table('assets_maintenance_completions')->count())->toBe(0)->and(DB::table('assets_maintenance_costs')->count())->toBe(0);
    $this->post('/assets/maintenance/'.$work->id.'/complete', [...$base, 'cost' => '175.35'])->assertRedirect();
    $original = DB::table('assets_maintenance_costs')->sole();
    $next = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset, ['action' => 'Check display']));
    $this->assetsSettings['currency'] = Currency::current() === 'USD' ? 'EUR' : 'USD';
    assetReloadSettings($this);
    $data = ['asset_version' => $asset->fresh()->version, 'version' => $next->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available'];
    $this->postJson('/assets/maintenance/'.$next->id.'/complete', [...$data, 'cost' => '1.00'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    $this->post('/assets/maintenance/'.$next->id.'/complete', $data)->assertRedirect();
    expect(DB::table('assets_maintenance_costs')->sole())->toEqual($original);
    $this->assetsSettings['modules'] = [];
    assetReloadSettings($this);
    expect(app(Currency::class)->consistent())->toBeFalse();
});

it('keeps document redirects scoped to the parent and independently permissioned with retained archived references', function () {
    $user = assetStaff();
    $asset = assetCreate($user);
    $this->actingAs($user)->post('/assets/register/'.$asset->id.'/documents', ['name' => 'Equipment guide', 'url' => 'https://documents.example.test/equipment-guide'])->assertRedirect();
    $id = DB::table('assets_documents')->sole()->id;
    $this->get('/assets/documents/'.$id)->assertRedirect('https://documents.example.test/equipment-guide');
    $other = assetStaff();
    $this->actingAs($other)->get('/assets/documents/'.$id)->assertNotFound();
    $this->actingAs($user);
    $user->revokePermissionTo('assets.documents.view');
    $this->get('/assets/documents/'.$id)->assertForbidden();
    $response = $this->get('/assets/register/'.$asset->id)->assertOk();
    expect(json_encode($response->inertiaProps()))->not->toContain('equipment-guide', 'Equipment guide');
    $user->givePermissionTo('assets.documents.view');
    $this->post('/assets/documents/'.$id.'/archive', ['version' => 1])->assertRedirect();
    $this->postJson('/assets/documents/'.$id.'/archive', ['version' => 1])->assertConflict();
    expect(DB::table('assets_documents')->sole()->url)->toBe('https://documents.example.test/equipment-guide');
    $this->get('/assets/documents/'.$id)->assertRedirect('https://documents.example.test/equipment-guide');
    foreach (['http://example.test/a', 'javascript:alert(1)', 'https://user:pass@example.test/a'] as $url) {
        $this->postJson('/assets/register/'.$asset->id.'/documents', ['name' => 'Invalid', 'url' => $url])->assertUnprocessable();
    }
    expect(DB::table('assets_documents')->count())->toBe(1);
});

it('matches advanced table filters and CSV scopes while retaining dormant layouts across disabled activation', function () {
    $user = assetOperator();
    $other = assetStaff();
    $asset = assetCreate($user, ['name' => '=HYPERLINK(A1)', 'code' => 'EQ-101', 'notes' => '=Private register note']);
    assetCreate($user, ['name' => 'Other equipment', 'code' => 'EQ-102', 'location' => 'Haifa']);
    assetCreate($other, ['name' => 'Private equipment', 'code' => 'EQ-103']);
    $query = ['section' => 'register', 'q' => 'HYPERLINK', 'filters' => [['id' => 'location', 'op' => 'eq', 'value' => 'Tel Aviv office']]];
    $this->actingAs($user)->get('/assets?'.http_build_query($query))->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1));
    $csv = $this->get('/assets/export?'.http_build_query($query))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK(A1)", "'=Private register note", 'Shira Cohen')->not->toContain('Private equipment', 'Other equipment');
    $readRow = function (string $csv): array {
        $lines = preg_split('/\r?\n/', trim($csv));

        return array_combine(str_getcsv($lines[0], escape: ''), str_getcsv($lines[1], escape: ''));
    };
    expect($readRow($csv))->toMatchArray(['owner' => 'Shira Cohen', 'notes' => "'=Private register note"]);
    app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['notes' => '=Private assignment note']));
    $assignmentCsv = $this->get('/assets/export?section=assignments')->assertOk()->streamedContent();
    expect($readRow($assignmentCsv))->toMatchArray(['notes' => "'=Private assignment note"]);
    app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset, ['description' => '=Private maintenance description']));
    $maintenanceCsv = $this->get('/assets/export?section=maintenance')->assertOk()->streamedContent();
    expect($readRow($maintenanceCsv))->toMatchArray(['description' => "'=Private maintenance description"]);
    $this->postJson('/preferences', ['table' => 'assets.register', 'columns' => ['code', 'name', 'location']])->assertOk();
    $before = $user->fresh()->display_preferences;
    $this->assetsSettings['modules'] = [];
    assetReloadSettings($this);
    $this->postJson('/preferences', ['table' => 'assets.register', 'columns' => ['name']])->assertUnprocessable();
    expect($user->fresh()->display_preferences)->toBe($before);
    $this->assetsSettings['modules'] = ['assets'];
    assetReloadSettings($this);
    $this->get('/assets?section=register')->assertInertia(fn (Assert $page) => $page->where('table.preferences.columns', ['code', 'name', 'location']));
    $user->revokePermissionTo('assets.export');
    $this->get('/assets/export?section=register')->assertForbidden();
});

it('stops reminders after stale parent version owner access loss completion or module disablement', function () {
    $user = assetStaff(true);
    $other = assetStaff();
    $asset = assetCreate($user);
    $assignment = app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['expected_return_on' => '2026-10-24']));
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset));
    $reminders = app(WorkReminders::class);
    $reminders->enqueueDue();
    expect(WorkReminder::where('module', 'assets')->count())->toBe(2);
    $old = WorkReminder::where('module', 'assets')->where('source', 'maintenance')->sole();
    $this->actingAs($user)->patch('/assets/register/'.$asset->id, [...assetData($user), 'version' => $asset->fresh()->version, 'status' => 'in-use', 'notes' => 'Updated schedule note.'])->assertRedirect();
    expect($reminders->preview($old))->toBeNull();
    $reminders->enqueueDue();
    $fresh = WorkReminder::where('module', 'assets')->where('source', 'maintenance')->latest('id')->first();
    $user->revokePermissionTo('assets.view.all');
    DB::table('assets_register')->where('id', $asset->id)->update(['owner_id' => $other->id]);
    expect($reminders->preview($fresh))->toBeNull();
    $count = WorkReminder::count();
    (new DeliverWorkReminder('assets', 'maintenance', $work->id, $fresh->record_version, $user->id))->handle();
    expect(WorkReminder::count())->toBe($count);
    $this->assetsSettings['modules'] = [];
    assetReloadSettings($this);
    expect($reminders->preview($fresh))->toBeNull();
    $reminders->enqueueDue();
    expect(WorkReminder::count())->toBe($count);
});

it('denies disabled direct reads writes documents exports and reminders while preserving records for reactivation', function () {
    $user = assetStaff();
    $asset = assetCreate($user);
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset));
    $this->actingAs($user)->post('/assets/register/'.$asset->id.'/documents', ['name' => 'Equipment guide', 'url' => 'https://documents.example.test/equipment-guide'])->assertRedirect();
    $document = DB::table('assets_documents')->sole();
    $counts = [DB::table('assets_register')->count(), DB::table('assets_maintenance')->count(), DB::table('assets_history')->count()];
    $this->assetsSettings['modules'] = [];
    assetReloadSettings($this);
    foreach (['/assets', '/assets/register/'.$asset->id, '/assets/maintenance/'.$work->id, '/assets/documents/'.$document->id, '/assets/export'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $this->patch('/assets/register/'.$asset->id, [...assetData($user), 'version' => $asset->fresh()->version])->assertNotFound();
    $this->post('/assets/register/'.$asset->id.'/assignments', assetAssignment($asset))->assertNotFound();
    expect([DB::table('assets_register')->count(), DB::table('assets_maintenance')->count(), DB::table('assets_history')->count()])->toBe($counts);
    $this->assetsSettings['modules'] = ['assets'];
    assetReloadSettings($this);
    $this->get('/assets/register/'.$asset->id)->assertOk();
    $this->get('/assets/documents/'.$document->id)->assertRedirect('https://documents.example.test/equipment-guide');
});

it('classifies existing shared customers and suppliers contextually without separate business modules or implicit identity grants', function () {
    $user = assetStaff();
    $other = assetStaff();
    $contact = Contact::create(['name' => 'Noam Levi', 'email' => 'assets-customer@example.test', 'search_key' => 'noamlevi', 'created_by' => $user->id]);
    $company = Company::create(['name' => 'Carmel Supply', 'email' => 'assets-supplier@example.test', 'search_key' => 'carmelsupply', 'created_by' => $user->id]);
    $foreign = Contact::create(['name' => 'Private contact', 'email' => 'assets-private@example.test', 'search_key' => 'private', 'created_by' => $other->id]);
    $this->actingAs($user)->post('/assets/classifications', ['role' => 'customer', 'contact_id' => $contact->id, 'responsible_id' => $user->id])->assertRedirect();
    $this->post('/assets/classifications', ['role' => 'supplier', 'company_id' => $company->id, 'responsible_id' => $user->id])->assertRedirect();
    $this->postJson('/assets/classifications', ['role' => 'donor', 'contact_id' => $contact->id])->assertUnprocessable();
    $this->postJson('/assets/classifications', ['role' => 'customer', 'contact_id' => $foreign->id])->assertNotFound();
    expect(DB::table('crm_identity_relationships')->count())->toBe(2)->and($user->can('identities.view.all'))->toBeFalse();
    $user->revokePermissionTo('identities.classifications.manage');
    $this->postJson('/assets/classifications', ['role' => 'customer', 'contact_id' => $foreign->id])->assertForbidden();
    foreach (['sales', 'operations', 'finance'] as $module) {
        expect(app(Modules::class)->enabled($module))->toBeFalse();
    }
});

it('archives abandoned maintenance without claiming completion and protects immutable history and costs', function () {
    $user = assetStaff();
    $asset = assetCreate($user);
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset));
    $this->actingAs($user)->patchJson('/assets/register/'.$asset->id, [...assetData($user), 'version' => $asset->fresh()->version, 'status' => 'retired'])->assertUnprocessable();
    $this->post('/assets/maintenance/'.$work->id.'/archive', ['asset_version' => $asset->fresh()->version, 'version' => $work->version])->assertRedirect();
    expect($work->fresh()->status)->toBe('planned')->and($work->fresh()->completed_on)->toBeNull()->and($work->fresh()->archived_at)->not->toBeNull()->and(DB::table('assets_maintenance_completions')->count())->toBe(0);
    $next = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset));
    $this->post('/assets/maintenance/'.$next->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $next->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available', 'cost' => '10.01'])->assertRedirect();
    expect(fn () => MaintenanceCompletion::sole()->update(['completed_on' => '2026-10-24']))->toThrow(LogicException::class)
        ->and(fn () => MaintenanceCost::sole()->update(['amount' => '0.01']))->toThrow(LogicException::class)
        ->and(fn () => AssetHistory::first()->delete())->toThrow(LogicException::class);
    $this->post('/assets/register/'.$asset->id.'/archive', ['version' => $asset->fresh()->version])->assertRedirect();
    expect($asset->fresh()->archived_at)->not->toBeNull()->and(DB::table('assets_maintenance_costs')->sole()->amount)->toBe('10.01');
});

it('records late costs and append-only corrections without rewriting completion snapshots or accepting stale retries', function () {
    $financeBefore = assetFinanceSnapshot();
    $user = assetStaff();
    $asset = assetCreate($user);
    $work = app(Maintenance::class)->save($user, $asset->id, assetMaintenance($user, $asset));
    $this->actingAs($user)->postJson('/assets/maintenance/'.$work->id.'/cost', ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'cost' => '175.35'])->assertUnprocessable();
    $this->post('/assets/maintenance/'.$work->id.'/complete', ['asset_version' => $asset->fresh()->version, 'version' => $work->version, 'completed_on' => '2026-10-25', 'asset_status' => 'available'])->assertRedirect();
    $completion = DB::table('assets_maintenance_completions')->sole();
    expect($completion->cost_id)->toBeNull()->and(DB::table('assets_maintenance_costs')->count())->toBe(0);
    $payload = ['asset_version' => $asset->fresh()->version, 'version' => $work->fresh()->version, 'cost' => '175.35'];
    $this->post('/assets/maintenance/'.$work->id.'/cost', $payload)->assertRedirect();
    $this->postJson('/assets/maintenance/'.$work->id.'/cost', $payload)->assertConflict();
    $this->post('/assets/maintenance/'.$work->id.'/cost', ['asset_version' => $asset->fresh()->version, 'version' => $work->fresh()->version, 'cost' => '185.35'])->assertRedirect();
    expect(DB::table('assets_maintenance_costs')->orderBy('id')->pluck('amount')->all())->toBe(['175.35', '185.35'])->and(DB::table('assets_maintenance_completions')->sole())->toEqual($completion);
    $this->get('/assets/maintenance/'.$work->id)->assertInertia(fn (Assert $page) => $page->where('record.cost', '185.35')->has('costHistory', 2));
    $user->revokePermissionTo('assets.costs.view');
    $response = $this->get('/assets/maintenance/'.$work->id)->assertOk();
    expect(json_encode($response->inertiaProps()))->not->toContain('175.35', '185.35');
    $user->givePermissionTo('assets.costs.view');
    $user->revokePermissionTo('assets.costs.manage');
    $payload = ['asset_version' => $asset->fresh()->version, 'version' => $work->fresh()->version, 'cost' => '195.35'];
    $this->postJson('/assets/maintenance/'.$work->id.'/cost', $payload)->assertForbidden();
    $user->givePermissionTo('assets.costs.manage');
    $this->assetsSettings['currency'] = Currency::current() === 'USD' ? 'EUR' : 'USD';
    assetReloadSettings($this);
    $this->postJson('/assets/maintenance/'.$work->id.'/cost', $payload)->assertUnprocessable()->assertJsonValidationErrors('currency');
    expect(DB::table('assets_maintenance_costs')->count())->toBe(2)->and(assetFinanceSnapshot())->toBe($financeBefore);
});

it('reschedules an open expected return without rewriting its target or original assignment and invalidates stale reminders', function () {
    $user = assetOperator();
    $asset = assetCreate($user);
    $assignment = app(Assignments::class)->create($user, $asset->id, assetAssignment($asset, ['expected_return_on' => '2026-10-24']));
    $reminders = app(WorkReminders::class);
    $reminders->enqueueDue();
    $notice = WorkReminder::where('module', 'assets')->where('source', 'returns')->sole();
    expect($reminders->preview($notice))->not->toBeNull();
    $payload = ['asset_version' => $asset->fresh()->version, 'version' => $assignment->version, 'expected_return_on' => '2026-11-01', 'notes' => 'Return date explicitly rescheduled.'];
    $this->actingAs($user)->patch('/assets/assignments/'.$assignment->id, $payload)->assertRedirect();
    expect($assignment->fresh()->expected_return_on->toDateString())->toBe('2026-11-01')->and($assignment->fresh()->assigned_on->toDateString())->toBe('2026-10-24')->and($assignment->fresh()->location)->toBe('Jerusalem office')->and($reminders->preview($notice))->toBeNull();
    $reminders->enqueueDue();
    expect(WorkReminder::where('module', 'assets')->count())->toBe(1);
    $this->patchJson('/assets/assignments/'.$assignment->id, $payload)->assertConflict();
    $current = ['asset_version' => $asset->fresh()->version, 'version' => $assignment->fresh()->version, 'expected_return_on' => null, 'notes' => 'Changed note.'];
    $this->patchJson('/assets/assignments/'.$assignment->id, [...$current, 'target_type' => 'customer', 'assigned_on' => '2026-10-25'])->assertRedirect();
    expect($assignment->fresh()->target_type)->toBe('location')->and($assignment->fresh()->assigned_on->toDateString())->toBe('2026-10-24');
    $this->post('/assets/assignments/'.$assignment->id.'/return', ['asset_version' => $asset->fresh()->version, 'version' => $assignment->fresh()->version, 'returned_on' => '2026-10-25', 'return_condition' => 'Returned before revised deadline'])->assertRedirect();
    $this->patchJson('/assets/assignments/'.$assignment->id, ['asset_version' => $asset->fresh()->version, 'version' => $assignment->fresh()->version, 'expected_return_on' => null])->assertConflict();
});
