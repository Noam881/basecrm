<?php

use App\Models\User;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\WorkReferenceContributor;
use Awove\Core\Installation;
use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkItem;
use Awove\Core\Modules;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReferences;
use Tests\Fixtures\WorkModule;

function instructionFixture(array $links): Module
{
    return new class($links) implements Module, WorkReferenceContributor
    {
        public int $calls = 0;

        public function __construct(private array $links) {}

        public function id(): string
        {
            return 'fixture-instructions';
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
            return ['fixture-instructions.view'];
        }

        public function navigation(): array
        {
            return [];
        }

        public function panels(): array
        {
            return [];
        }

        public function workReferences($user, WorkItem|Routine $record): array
        {
            $this->calls++;

            return $user->can('fixture-instructions.view') ? $this->links : [];
        }
    };
}

function instructionLink(array $overrides = []): array
{
    return [...['id' => 'fixture-instructions.instructions.42', 'label' => 'Review the fictional procedure', 'href' => '/instructions/42'], ...$overrides];
}

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->referencesManifest = tempnam(sys_get_temp_dir(), 'base-crm-references-');
    $manifest = Installation::manifest();
    $manifest['modules'] = [...$manifest['modules'], 'fixture-work', 'fixture-instructions'];
    file_put_contents($this->referencesManifest, json_encode($manifest));
    config(['core.manifest' => $this->referencesManifest]);
    app(Modules::class)->register(new WorkModule);
    $this->instructions = instructionFixture([instructionLink(['private' => 'never project this'])]);
    app(Modules::class)->register($this->instructions);
    $this->artisan('crm:install')->assertSuccessful();
    $this->reader = User::factory()->create(['name' => 'Yael Cohen', 'active' => true]);
    $this->reader->givePermissionTo(['fixture-work.view.own', 'fixture-work.create', 'fixture-instructions.view']);
    $this->work = app(WorkItems::class)->create($this->reader, 'fixture-work', 'tasks', ['title' => 'Review the fictional plan', 'due_on' => now()->toDateString()]);
});
afterEach(function () {
    unlink($this->referencesManifest);
});

function referencesDisable(string $path, string $module): void
{
    $manifest = json_decode(file_get_contents($path), true);
    $manifest['modules'] = array_values(array_diff($manifest['modules'], [$module]));
    file_put_contents($path, json_encode($manifest));
}

it('projects only scoped current instruction links for both shared work and routines', function () {
    $references = app(WorkReferences::class);
    expect($references->for($this->reader, $this->work))->toBe([instructionLink()]);
    $routine = app(Routines::class)->create($this->reader, 'fixture-work', 'routines', ['name' => 'Review fictional procedures', 'frequency' => 'Weekly', 'next_due_on' => now()->toDateString()]);
    expect($references->for($this->reader, $routine))->toBe([instructionLink()]);
    $this->reader->revokePermissionTo('fixture-instructions.view');
    expect($references->for($this->reader, $this->work))->toBe([]);
});

it('reloads parent ownership and fresh actor grants before evaluating optional providers', function () {
    $other = User::factory()->create(['active' => true]);
    WorkItem::whereKey($this->work->id)->update(['owner_id' => $other->id]);
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([])->and($this->instructions->calls)->toBe(0);
    WorkItem::whereKey($this->work->id)->update(['owner_id' => $this->reader->id]);
    $this->reader->revokePermissionTo('fixture-work.view.own');
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([])->and($this->instructions->calls)->toBe(0);
    $this->reader->givePermissionTo('fixture-work.view.own');
    User::whereKey($this->reader->id)->update(['active' => false]);
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([])->and($this->instructions->calls)->toBe(0);
});

it('does not invoke absent disabled providers or providers for disabled parent modules', function () {
    referencesDisable($this->referencesManifest, 'fixture-instructions');
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([])->and($this->instructions->calls)->toBe(0);
    referencesDisable($this->referencesManifest, 'fixture-work');
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([])->and($this->instructions->calls)->toBe(0);
    // An installed parent without any installed provider remains a useful ordinary work record.
    $registry = new Modules;
    $registry->register(new WorkModule);
    app()->instance(Modules::class, $registry);
    $manifest = json_decode(file_get_contents($this->referencesManifest), true);
    $manifest['modules'][] = 'fixture-work';
    file_put_contents($this->referencesManifest, json_encode($manifest));
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([]);
});

it('uses persisted module kind identity and does not expose references for forged unsaved or missing parents', function () {
    $this->work->kind = 'not-a-kind';
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([]);
    $this->work->refresh();
    expect(app(WorkReferences::class)->for($this->reader, new WorkItem(['module' => 'fixture-work', 'kind' => 'tasks', 'id' => $this->work->id])))->toBe([]);
    $this->work->id = PHP_INT_MAX;
    expect(app(WorkReferences::class)->for($this->reader, $this->work))->toBe([])->and($this->instructions->calls)->toBe(0);
});

it('rejects duplicate foreign malformed and unsafe instruction references before projection', function () {
    foreach ([
        [instructionLink(), instructionLink()],
        [instructionLink(['id' => 'another-module.instructions.42'])],
        [instructionLink(['href' => 'https://example.test/private'])],
        [instructionLink(['href' => '//example.test/private'])],
        [instructionLink(['href' => '/\\example.test/private'])],
        [instructionLink(['href' => "/instructions/42\n"])],
        [instructionLink(['label' => ' '])],
    ] as $links) {
        $registry = new Modules;
        $registry->register(new WorkModule);
        $registry->register(instructionFixture($links));
        app()->instance(Modules::class, $registry);
        expect(fn () => app(WorkReferences::class)->for($this->reader, $this->work))->toThrow(RuntimeException::class);
    }
});
