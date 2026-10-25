<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Models\EmployeeReference;
use Awove\Core\Modules;
use Awove\Core\Services\EmployeeReferences;
use Awove\Finance\Payroll\Document;
use Awove\Finance\Payroll\History;
use Awove\Finance\Payroll\PayrollCapability;
use Awove\Finance\Payroll\Preparation;
use Awove\Finance\Payroll\Preparations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $manifest = Installation::manifest();
    $manifest['modules'] = ['finance'];
    $manifest['capabilities'] = ['sales' => false, 'finance:payroll' => true, 'finance:collections' => false, 'finance:expenses' => false];
    $manifest['currency'] = 'ILS';
    $manifest['timezone'] = 'Asia/Jerusalem';
    $manifest['payroll_inputs'] = [
        ['key' => 'hours', 'label' => 'Reported hours', 'type' => 'decimal', 'unit' => 'hours', 'scale' => 4, 'required' => true],
        ['key' => 'expenses', 'label' => 'Agreed expenses', 'type' => 'money', 'unit' => 'ILS', 'scale' => 2, 'required' => false],
        ['key' => 'review_note', 'label' => 'Review note', 'type' => 'text', 'unit' => '', 'scale' => 2, 'required' => true],
        ['key' => 'reported_on', 'label' => 'Report date', 'type' => 'date', 'unit' => '', 'scale' => 2, 'required' => false],
    ];
    $this->payrollManifest = tempnam(sys_get_temp_dir(), 'base-crm-payroll-');
    file_put_contents($this->payrollManifest, json_encode($manifest));
    config(['core.manifest' => $this->payrollManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    unlink($this->payrollManifest);
});
function payrollActor(bool $all = false, string $name = 'Noa Cohen'): User
{
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole('Employee');
    $permissions = (new PayrollCapability)->permissions();
    if (! $all) {
        $permissions = array_values(array_filter($permissions, fn ($p) => ! in_array($p, ['finance.payroll.view.all', 'finance.payroll.edit.all', 'finance.payroll.assign'], true)));
    }
    $user->givePermissionTo([...$permissions, 'employees.references.view', 'employees.references.manage']);

    return $user;
}
function payrollData(User $actor, array $extra = []): array
{
    $employee = app(EmployeeReferences::class)->create($actor, ['name' => 'Shira Levi', 'active' => false]);

    return ['employee_reference_id' => $employee->id, 'period_start_on' => '2026-10-01', 'period_end_on' => '2026-10-31', ...$extra];
}
function payrollStatus(callable $call, int $status): void
{
    try {
        $call();
        test()->fail('Expected an HTTP access/concurrency error.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe($status);
    }
}
function payrollConfig(array $change): void
{
    file_put_contents(config('core.manifest'), json_encode(array_replace(Installation::manifest(), $change)));
}

it('prepares manual final payroll for an inactive nonlogin reference without HR recruitment Sales or ledger capabilities', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $users = User::count();
    $contacts = Contact::count();
    $record = $service->create($actor, payrollData($actor));
    expect(app(Modules::class)->enabled('finance:payroll'))->toBeTrue()->and(app(Modules::class)->enabled('hr'))->toBeFalse()
        ->and(app(Modules::class)->enabled('sales'))->toBeFalse()->and(app(Modules::class)->enabled('finance:collections'))->toBeFalse();
    expect($record->version)->toBe(1)->and($record->getAttribute('status'))->toBe('pending-input')->and($record->getAttribute('inputs')['hours'])->toBeNull()
        ->and(User::count())->toBe($users)->and(Contact::count())->toBe($contacts)->and(EmployeeReference::count())->toBe(1);
    $this->actingAs($actor)->get('/finance/payroll/'.$record->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('record.employee.active', false)->where('record.employee.name', 'Shira Levi')->where('record.period_start_on', '2026-10-01'));
});

it('snapshots agreed definitions and exact values without calculating salary or reinterpreting changed configuration', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $record = $service->create($actor, payrollData($actor, ['inputs' => ['hours' => '165.1234', 'expenses' => '999999999999.99', 'review_note' => 'Reviewed manually', 'reported_on' => '2026-10-31']]));
    $snapshot = $record->getAttribute('input_schema');
    payrollConfig(['payroll_inputs' => [['key' => 'replacement', 'label' => 'New definition', 'type' => 'text', 'unit' => '', 'scale' => 2, 'required' => true]]]);
    $record = $service->update($actor, $record->id, ['version' => 1, 'status' => 'ready-for-review']);
    expect($record->getAttribute('input_schema'))->toEqual($snapshot)->and($record->getAttribute('inputs'))->toMatchArray(['hours' => '165.1234', 'expenses' => '999999999999.99', 'review_note' => 'Reviewed manually'])
        ->and($record->getAttribute('status'))->toBe('ready-for-review')->and($record->getAttributes())->not->toHaveKeys(['net_pay', 'gross_pay', 'calculated_salary']);
    expect(fn () => $service->update($actor, $record->id, ['version' => 2, 'inputs' => ['replacement' => 'Forged reinterpretation']]))->toThrow(ValidationException::class);
    expect(fn () => $service->update($actor, $record->id, ['version' => 2, 'input_schema' => []]))->toThrow(ValidationException::class);
});

it('enforces required agreed values before ready and validates precision dates and field names atomically', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $record = $service->create($actor, payrollData($actor));
    foreach ([['status' => 'ready-for-review'], ['status' => 'ready-for-review', 'inputs' => ['hours' => '10', 'review_note' => '   ']], ['inputs' => ['hours' => '1.12345']], ['inputs' => ['expenses' => '1.999']], ['inputs' => ['hours' => 0.1]], ['inputs' => ['reported_on' => '2026-02-30']], ['inputs' => ['unknown' => 'x']]] as $invalid) {
        expect(fn () => $service->update($actor, $record->id, ['version' => 1, ...$invalid]))->toThrow(ValidationException::class);
        expect($record->fresh()->version)->toBe(1);
    }
    expect(History::where('preparation_id', $record->id)->count())->toBe(1);
});

it('requires manual sent date retains prior status history and prevents stale overwrite', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $record = $service->create($actor, payrollData($actor, ['inputs' => ['hours' => '10.25', 'review_note' => 'Approved inputs']]));
    expect(fn () => $service->update($actor, $record->id, ['version' => 1, 'status' => 'sent-to-payroll']))->toThrow(ValidationException::class);
    $service->update($actor, $record->id, ['version' => 1, 'status' => 'sent-to-payroll', 'sent_on' => '2026-11-02']);
    payrollStatus(fn () => $service->update($actor, $record->id, ['version' => 1, 'notes' => 'Stale']), 409);
    $record = $service->update($actor, $record->id, ['version' => 2, 'status' => 'pending-input']);
    expect($record->getAttribute('sent_on'))->toBeNull()->and($service->history($actor, $record->id)[0]['details']['previous_status'])->toBe('sent-to-payroll')
        ->and(json_encode($service->history($actor, $record->id)))->toContain('2026-11-02');
});

it('enforces exact employee period uniqueness including archived preparations and invalid periods', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $data = payrollData($actor);
    $record = $service->create($actor, $data);
    expect(fn () => $service->create($actor, $data))->toThrow(ValidationException::class);
    $service->archive($actor, $record->id, ['version' => 1]);
    expect(fn () => $service->create($actor, $data))->toThrow(ValidationException::class);
    expect(fn () => $service->create($actor, [...$data, 'period_start_on' => '2026-12-31', 'period_end_on' => '2026-12-01']))->toThrow(ValidationException::class);
    expect(Preparation::count())->toBe(1)->and($service->find($actor, $record->id)->getAttribute('archived_at'))->not->toBeNull();
    payrollStatus(fn () => $service->update($actor, $record->id, ['version' => 2, 'notes' => 'Archived edit']), 403);
});

it('denies private record documents exports and history to other owners without broad payroll permission', function () {
    $owner = payrollActor();
    $other = payrollActor(name: 'Uri Erez');
    $service = app(Preparations::class);
    $record = $service->create($owner, payrollData($owner, ['notes' => 'Private payroll note']));
    $document = $service->addDocument($owner, $record->id, ['version' => 1, 'label' => 'Reviewed inputs', 'url' => 'https://documents.example.test/payroll-inputs']);
    expect(fn () => $service->find($other, $record->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $service->history($other, $record->id))->toThrow(ModelNotFoundException::class);
    $this->actingAs($other)->get('/finance/payroll/'.$record->id)->assertNotFound();
    $this->get('/finance/payroll/'.$record->id.'/documents/'.$document->id)->assertNotFound();
    $csv = $this->get('/finance/payroll/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Private payroll note', 'Shira Levi');
    $other->givePermissionTo('finance.payroll.view.all');
    $this->get('/finance/payroll/'.$record->id)->assertOk();
    $other->revokePermissionTo('finance.payroll.export');
    $this->get('/finance/payroll/export')->assertForbidden();
});

it('redacts lost employee reference access while retaining an unchanged employee on unrelated edits', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $data = payrollData($actor);
    $record = $service->create($actor, $data);
    $actor->revokePermissionTo(['employees.references.view', 'employees.references.manage']);
    expect($service->describe($actor, $record))->toMatchArray(['employee_reference_id' => null, 'employee' => null]);
    $service->update($actor, $record->id, ['version' => 1, 'employee_reference_id' => null, 'notes' => 'Ordinary edit retains hidden identity']);
    expect($record->fresh()->getAttribute('employee_reference_id'))->toBe($data['employee_reference_id']);
    $this->actingAs($actor)->get('/finance/payroll?q=Shira')->assertInertia(fn (Assert $p) => $p->has('table.page.data', 0));
    $csv = $this->get('/finance/payroll/export')->assertOk()->streamedContent();
    expect($csv)->not->toContain('Shira Levi');
    payrollStatus(fn () => $service->create($actor, [...$data, 'period_start_on' => '2026-11-01', 'period_end_on' => '2026-11-30']), 403);
});

it('requires authorized reassignment and retains unchanged null or inactive historical owners', function () {
    $manager = payrollActor(true);
    $owner = payrollActor(name: 'Daniel Cohen');
    $service = app(Preparations::class);
    $record = $service->create($manager, payrollData($manager, ['owner_id' => null]));
    $manager->revokePermissionTo('finance.payroll.assign');
    $service->update($manager, $record->id, ['version' => 1, 'notes' => 'Unassigned history retained']);
    expect($record->fresh()->getAttribute('owner_id'))->toBeNull();
    payrollStatus(fn () => $service->update($manager, $record->id, ['version' => 2, 'owner_id' => $owner->id]), 403);
    $manager->givePermissionTo('finance.payroll.assign');
    $service->update($manager, $record->id, ['version' => 2, 'owner_id' => $owner->id]);
    $manager->revokePermissionTo('finance.payroll.assign');
    $owner->forceFill(['active' => false])->save();
    $service->update($manager, $record->id, ['version' => 3, 'notes' => 'Inactive owner retained']);
    expect($record->fresh()->getAttribute('owner_id'))->toBe($owner->id);
});

it('protects documents and history append-only with versioned atomic document attachment', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $record = $service->create($actor, payrollData($actor));
    expect(fn () => $service->addDocument($actor, $record->id, ['version' => 1, 'label' => 'Unsafe link', 'url' => 'javascript:alert(1)']))->toThrow(ValidationException::class);
    expect($record->fresh()->version)->toBe(1)->and(Document::count())->toBe(0);
    $document = $service->addDocument($actor, $record->id, ['version' => 1, 'label' => 'Approved inputs', 'url' => 'https://documents.example.test/approved']);
    payrollStatus(fn () => $service->addDocument($actor, $record->id, ['version' => 1, 'label' => 'Stale', 'url' => 'https://documents.example.test/stale']), 409);
    expect($record->fresh()->version)->toBe(2)->and(Document::count())->toBe(1)->and(History::where('preparation_id', $record->id)->count())->toBe(2);
    expect(fn () => $document->update(['label' => 'Forged']))->toThrow(LogicException::class);
    expect(fn () => $document->delete())->toThrow(LogicException::class);
    expect(fn () => History::first()->delete())->toThrow(LogicException::class);
});

it('disables only payroll entrypoints and background contributions without deleting retained preparations', function () {
    $actor = payrollActor();
    $service = app(Preparations::class);
    $record = $service->create($actor, payrollData($actor));
    $document = $service->addDocument($actor, $record->id, ['version' => 1, 'label' => 'Inputs', 'url' => 'https://documents.example.test/inputs']);
    $manifest = Installation::manifest();
    $manifest['capabilities']['finance:payroll'] = false;
    payrollConfig(['capabilities' => $manifest['capabilities']]);
    $this->actingAs($actor)->get('/finance/payroll')->assertForbidden();
    $this->get('/finance/payroll/export')->assertForbidden();
    $this->get('/finance/payroll/'.$record->id.'/documents/'.$document->id)->assertForbidden();
    $this->post('/finance/payroll', payrollData($actor))->assertForbidden();
    expect(Preparation::count())->toBe(1)->and($service->query($actor)->count())->toBe(0)->and((new PayrollCapability)->widgets($actor))->toBe([]);
    $manifest['capabilities']['finance:payroll'] = true;
    payrollConfig(['capabilities' => $manifest['capabilities']]);
    expect($service->find($actor, $record->id)->id)->toBe($record->id);
});
