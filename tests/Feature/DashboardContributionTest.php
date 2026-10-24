<?php

use App\Models\User;
use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Spatie\Permission\Models\Permission;

function dashboardFixtureModule(array $widgets): Module
{
    return new class($widgets) implements DashboardContributor, Module
    {
        public int $calls = 0;

        public function __construct(private array $contributions) {}

        public function id(): string
        {
            return 'widget-fixture';
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
            return [];
        }

        public function permissions(): array
        {
            return ['widget-fixture.view'];
        }

        public function navigation(): array
        {
            return [];
        }

        public function panels(): array
        {
            return [];
        }

        public function widgets($user): array
        {
            $this->calls++;

            return $this->contributions;
        }
    };
}

function dashboardFixtureWidget(array $overrides = []): array
{
    return [...['id' => 'widget-fixture.open', 'label' => 'Open fixture work', 'value' => 7, 'href' => '/fixture?view=open', 'permission' => 'widget-fixture.view'], ...$overrides];
}

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->manifestPath = tempnam(sys_get_temp_dir(), 'crm-dashboard-');
    $this->manifestData = Installation::manifest();
    $this->manifestData['modules'] = ['widget-fixture'];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    config(['core.manifest' => $this->manifestPath]);
    Permission::findOrCreate('widget-fixture.view', 'web');
    Permission::findOrCreate('widget-fixture.view.all', 'web');
});
afterEach(function () {
    if (is_file($this->manifestPath)) {
        unlink($this->manifestPath);
    }
});

it('returns only authorized enabled module widgets and exposes no authorization metadata', function () {
    $user = User::factory()->create(['name' => 'Yael Cohen', 'active' => true]);
    $registry = new Modules;
    $registry->register(dashboardFixtureModule([dashboardFixtureWidget()]));
    expect($registry->dashboardWidgets($user))->toBe([]);
    $user->givePermissionTo('widget-fixture.view');
    expect($registry->dashboardWidgets($user))->toBe([['id' => 'widget-fixture.open', 'module' => 'widget-fixture', 'label' => 'Open fixture work', 'value' => 7, 'href' => '/fixture?view=open']]);
    $user->revokePermissionTo('widget-fixture.view');
    expect($registry->dashboardWidgets($user))->toBe([]);
});

it('does not evaluate disabled or absent contributors or show fabricated zero widgets', function () {
    $user = User::factory()->create(['active' => true]);
    $user->givePermissionTo('widget-fixture.view');
    $registry = new Modules;
    $module = dashboardFixtureModule([dashboardFixtureWidget(['value' => 0])]);
    $registry->register($module);
    $this->manifestData['modules'] = [];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    expect($registry->dashboardWidgets($user))->toBe([])->and($module->calls)->toBe(0);
    expect((new Modules)->dashboardWidgets($user))->toBe([]);
    $this->manifestData['modules'] = ['widget-fixture'];
    file_put_contents($this->manifestPath, json_encode($this->manifestData));
    expect($registry->dashboardWidgets($user)[0]['value'])->toBe(0)->and($module->calls)->toBe(1);
    $user->active = false;
    expect($registry->dashboardWidgets($user))->toBe([])->and($module->calls)->toBe(1);
});

it('supports alternative view permissions and exact string amounts', function () {
    $user = User::factory()->create(['active' => true]);
    $user->givePermissionTo('widget-fixture.view.all');
    $registry = new Modules;
    $registry->register(dashboardFixtureModule([dashboardFixtureWidget(['value' => '100000000000.01', 'anyPermissions' => ['widget-fixture.view.all']])]));
    expect($registry->dashboardWidgets($user)[0]['value'])->toBe('100000000000.01');
});

it('rejects duplicate or invalid module widget identifiers permissions and external URLs', function () {
    $user = User::factory()->create(['active' => true]);
    $user->givePermissionTo('widget-fixture.view');
    foreach ([
        [dashboardFixtureWidget(), dashboardFixtureWidget()],
        [dashboardFixtureWidget(['id' => 'another-module.open'])],
        [dashboardFixtureWidget(['href' => 'https://example.test/'])],
        [dashboardFixtureWidget(['href' => '//example.test/'])],
        [dashboardFixtureWidget(['href' => '/\\example.test/'])],
        [dashboardFixtureWidget(['permission' => 'finance.view'])],
        [dashboardFixtureWidget(['anyPermissions' => ['finance.view']])],
        [dashboardFixtureWidget(['value' => 0.1])],
    ] as $widgets) {
        $registry = new Modules;
        $registry->register(dashboardFixtureModule($widgets));
        expect(fn () => $registry->dashboardWidgets($user))->toThrow(RuntimeException::class);
    }
});
