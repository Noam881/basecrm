<?php

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\Contracts\CapabilityProvider;
use Awove\Core\Contracts\Module;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverReminder;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Status;
use Awove\Core\Modules;
use Awove\Core\SalesModule;
use Awove\Core\Services\Leads;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

function lifecycleModule(string $id, array $dependencies = []): Module
{
    return new class($id, $dependencies) implements CapabilityProvider, Module
    {
        public function __construct(private string $key, private array $requires) {}

        public function id(): string
        {
            return $this->key;
        }

        public function version(): string
        {
            return '1.0.0';
        }

        public function contract(): int
        {
            return 1;
        }

        public function dependencies(): array
        {
            return $this->requires;
        }

        public function capabilities(): array
        {
            return ['records', 'restricted'];
        }

        public function permissions(): array
        {
            return [$this->key.'.view'];
        }

        public function navigation(): array
        {
            return [];
        }

        public function panels(): array
        {
            return [];
        }
    };
}

beforeEach(function () {
    $this->withoutVite();
    $this->manifestPath = tempnam(sys_get_temp_dir(), 'crm-lifecycle-');
    $this->manifestData = Installation::manifest();
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    config(['core.manifest' => $this->manifestPath]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    if (is_file($this->manifestPath)) {
        unlink($this->manifestPath);
    }
});

it('retains legacy default Sales while distinguishing installed enabled and permissioned modules', function () {
    expect(app(Modules::class)->enabled('sales'))->toBeTrue()
        ->and(app(Modules::class)->enabled('customer-service'))->toBeFalse();
    $this->manifestData['modules'] = ['customer-service'];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    app(Modules::class)->validate();
    expect(app(Modules::class)->enabled('customer-service'))->toBeTrue();
    $u = User::factory()->create(['active' => true]);
    $u->assignRole('Employee');
    $this->actingAs($u)->get('/service')->assertForbidden();
    expect(app(Modules::class)->navigation($u))->toBe([])
        ->and(Role::findByName('Administrator')->hasPermissionTo('customer-service.view.all'))->toBeFalse();
});

it('denies disabled Sales data direct requests and contributions without deleting records', function () {
    $u = User::factory()->create(['active' => true]);
    $u->assignRole('Employee');
    $c = Contact::create(['name' => 'Noa Levi', 'created_by' => $u->id, 'search_key' => 'noalevi']);
    $lead = app(Leads::class)->create($u, ['contact_id' => $c->id, 'status_id' => 'new', 'owner_id' => $u->id]);
    $lead->tasks()->create(['assignee_id' => $u->id, 'title' => 'Follow up with Noa', 'due_at' => now(), 'remind_at' => now()->subMinute()]);
    Queue::fake();
    $this->manifestData['capabilities'] = ['sales' => false];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    expect(Gate::forUser($u)->allows('view', $lead))->toBeFalse();
    expect(fn () => app(Leads::class)->create($u, ['title' => 'Forbidden', 'contact_id' => $c->id, 'status_id' => 'new', 'owner_id' => $u->id]))->toThrow(HttpException::class);
    $this->actingAs($u)->get('/leads')->assertNotFound();
    $this->get('/leads/'.$lead->id)->assertNotFound();
    $this->post('/leads', [])->assertNotFound();
    $this->patch('/leads/'.$lead->id, ['version' => 1, 'title' => 'Hidden'])->assertNotFound();
    $this->get('/leads/'.$lead->id.'/proposals')->assertNotFound();
    $this->get('/tasks')->assertNotFound();
    $this->artisan('crm:remind')->assertSuccessful();
    Queue::assertNotPushed(DeliverReminder::class);
    $this->get('/dashboard')->assertInertia(fn (Assert $p) => $p->component('Core/Workspace')->missing('metrics')->where('salesAvailable', false));
    expect(Access::leads($u)->count())->toBe(0)->and(Lead::find($lead->id)->contact_id)->toBe($c->id);
    $this->manifestData['capabilities'] = [];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    $this->get('/leads/'.$lead->id)->assertOk();
});

it('requires explicit independent capabilities and prevents disabling a required dependency', function () {
    $registry = new Modules;
    $registry->register(new SalesModule);
    $registry->register(lifecycleModule('finance'));
    $registry->register(lifecycleModule('reporting', ['finance:records']));
    $this->manifestData['modules'] = ['finance', 'reporting'];
    $this->manifestData['capabilities'] = ['finance:records' => true];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    $registry->validate();
    expect($registry->enabled('finance:records'))->toBeTrue()->and($registry->enabled('finance:restricted'))->toBeFalse();
    $this->manifestData['capabilities']['finance:records'] = false;
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    expect($registry->enabled('reporting'))->toBeFalse()
        ->and(fn () => $registry->validate())->toThrow(RuntimeException::class, 'dependency');
});

it('rejects circular dependencies absent code unknown capabilities and invalid identifiers', function () {
    $registry = new Modules;
    $registry->register(lifecycleModule('alpha-module', ['beta-module']));
    $registry->register(lifecycleModule('beta-module', ['alpha-module']));
    $this->manifestData['modules'] = ['alpha-module', 'beta-module'];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    expect(fn () => $registry->validate())->toThrow(RuntimeException::class, 'circular');
    $this->manifestData['modules'] = ['missing-module'];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    expect(fn () => $registry->validate())->toThrow(RuntimeException::class, 'not installed');
    $this->manifestData['modules'] = [];
    $this->manifestData['capabilities'] = ['alpha-module:unknown' => true];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    expect(fn () => $registry->validate())->toThrow(RuntimeException::class, 'Unknown');
    expect(fn () => $registry->register(lifecycleModule('../unsafe')))->toThrow(RuntimeException::class, 'identifier');
});

it('installs source Sales defaults without renaming historical or client stages', function () {
    $this->manifestData['legacy_sales_stages'] = false;
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    Status::find('new')->update(['name' => 'Incoming enquiries', 'position' => 31]);
    $this->artisan('crm:install')->assertSuccessful();
    expect(Status::find('new')->name)->toBe('Incoming enquiries')->and(Status::find('new')->position)->toBe(31)
        ->and(Status::find('discussion')->name)->toBe('In discussion')
        ->and(Status::find('contacted'))->not->toBeNull();
});

it('provisions optional permissions only by explicit operator action and retains revoked grants on install', function () {
    $u = User::factory()->create(['active' => true]);
    $u->assignRole('Employee');
    $this->artisan('crm:permissions', ['email' => $u->email, '--grant' => ['customer-service.view.own']])->assertSuccessful();
    expect($u->fresh()->can('customer-service.view.own'))->toBeTrue();
    $this->artisan('crm:permissions', ['email' => $u->email, '--revoke' => ['customer-service.view.own']])->assertSuccessful();
    $this->artisan('crm:install')->assertSuccessful();
    expect($u->fresh()->can('customer-service.view.own'))->toBeFalse();
    $this->artisan('crm:permissions', ['email' => $u->email, '--grant' => ['unknown.permission']])->assertFailed();
});
