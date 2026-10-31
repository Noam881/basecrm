<?php

use App\Models\User;
use Awove\Core\Contracts\LeadPanel;
use Awove\Core\Contracts\Module;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Spatie\Permission\Models\Permission;

function leadPanelFixture(array $panels): Module
{
    return new class($panels) implements Module
    {
        public int $calls = 0;

        public function __construct(private array $panels) {}

        public function id(): string
        {
            return 'panel-fixture';
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
            return ['panel-fixture.view.own', 'panel-fixture.view.all'];
        }

        public function navigation(): array
        {
            return [];
        }

        public function panels(): array
        {
            $this->calls++;

            return $this->panels;
        }
    };
}
function fixtureLeadPanel(array $overrides = []): LeadPanel
{
    return new LeadPanel(...[...['id' => 'panel-fixture.profile', 'label' => 'Fictional profile', 'component' => 'Fixture/Panel', 'endpoint' => '/modules/panel-fixture/leads/{lead}', 'permission' => 'panel-fixture.view.own'], ...$overrides]);
}
beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->panelsManifest = tempnam(sys_get_temp_dir(), 'base-crm-panels-');
    $manifest = Installation::manifest();
    $manifest['modules'][] = 'panel-fixture';
    file_put_contents($this->panelsManifest, json_encode($manifest));
    config(['core.manifest' => $this->panelsManifest]);
    foreach (['panel-fixture.view.own', 'panel-fixture.view.all'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
});
afterEach(function () {
    unlink($this->panelsManifest);
});

it('preserves five argument panels and supports alternative views without exposing permission metadata', function () {
    $user = User::factory()->create(['active' => true]);
    $registry = new Modules;
    $registry->register(leadPanelFixture([fixtureLeadPanel(['anyPermissions' => ['panel-fixture.view.all']])]));
    expect($registry->panels($user))->toBe([]);
    $user->givePermissionTo('panel-fixture.view.all');
    expect($registry->panels($user))->toBe([['id' => 'panel-fixture.profile', 'label' => 'Fictional profile', 'component' => 'Fixture/Panel', 'endpoint' => '/modules/panel-fixture/leads/{lead}']]);
    $user->revokePermissionTo('panel-fixture.view.all');
    $user->givePermissionTo('panel-fixture.view.own');
    $legacy = new Modules;
    $legacy->register(leadPanelFixture([fixtureLeadPanel()]));
    expect($legacy->panels($user))->toHaveCount(1);
    $user->revokePermissionTo('panel-fixture.view.own');
    expect($legacy->panels($user))->toBe([]);
});

it('does not evaluate disabled absent or freshly inactive panel contributors', function () {
    $user = User::factory()->create(['active' => true]);
    $user->givePermissionTo('panel-fixture.view.all');
    $module = leadPanelFixture([fixtureLeadPanel(['anyPermissions' => ['panel-fixture.view.all']])]);
    $registry = new Modules;
    $registry->register($module);
    $manifest = json_decode(file_get_contents($this->panelsManifest), true);
    $manifest['modules'] = array_values(array_diff($manifest['modules'], ['panel-fixture']));
    file_put_contents($this->panelsManifest, json_encode($manifest));
    expect($registry->panels($user))->toBe([])->and($module->calls)->toBe(0)->and((new Modules)->panels($user))->toBe([]);
    $manifest['modules'][] = 'panel-fixture';
    file_put_contents($this->panelsManifest, json_encode($manifest));
    User::whereKey($user->id)->update(['active' => false]);
    expect($registry->panels($user))->toBe([])->and($module->calls)->toBe(0);
});

it('rejects undeclared crossmodule permissions duplicate IDs and unsafe endpoints even without view grants', function () {
    $user = User::factory()->create(['active' => true]);
    foreach ([
        [fixtureLeadPanel(), fixtureLeadPanel()],
        [fixtureLeadPanel(['permission' => 'finance.view'])],
        [fixtureLeadPanel(['permission' => 'panel-fixture.undeclared'])],
        [fixtureLeadPanel(['anyPermissions' => ['finance.view']])],
        [fixtureLeadPanel(['anyPermissions' => ['panel-fixture.undeclared']])],
        [fixtureLeadPanel(['endpoint' => '//example.test/panel'])],
        [fixtureLeadPanel(['endpoint' => '/\\example.test/panel'])],
        [fixtureLeadPanel(['component' => '../Core/Lead'])],
        [fixtureLeadPanel(['id' => 'foreign.panel'])],
    ] as $panels) {
        $registry = new Modules;
        $registry->register(leadPanelFixture($panels));
        expect(fn () => $registry->panels($user))->toThrow(RuntimeException::class);
    }
});
