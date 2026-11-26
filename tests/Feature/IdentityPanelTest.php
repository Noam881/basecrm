<?php

use App\Models\User;
use Awove\Core\Contracts\IdentityPanel;
use Awove\Core\Contracts\IdentityPanelContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\IdentityPanels;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Modules;
use Illuminate\Auth\Access\AuthorizationException;

final class IdentityProfileTestModule implements IdentityPanelContributor, Module
{
    public function __construct(private ?array $descriptors = null) {}

    public function id(): string
    {
        return 'identity-profile-test';
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
        return ['identity-profile-test.view'];
    }

    public function navigation(): array
    {
        return [];
    }

    public function panels(): array
    {
        return [];
    }

    public function identityPanels($user, Contact|Company $identity): array
    {
        return $this->descriptors ?? [new IdentityPanel('identity-profile-test.customer', 'Customer extension', 'Example/Customer', '/identity-profile-test/'.$identity->id, 'identity-profile-test.view')];
    }
}

beforeEach(function () {
    $this->withoutVite();
    app(Modules::class)->register(new IdentityProfileTestModule);
    $manifest = Installation::manifest();
    $manifest['modules'] = ['identity-profile-test'];
    $path = tempnam(sys_get_temp_dir(), 'identity-panels-');
    file_put_contents($path, json_encode($manifest));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    $path = config('core.manifest');
    if (is_string($path) && str_starts_with(basename($path), 'identity-panels-')) {
        @unlink($path);
    }
});

it('composes authorized customer panels only while the module and its permission are available', function () {
    $user = User::factory()->create(['name' => 'Tamar Ben Ami']);
    $user->assignRole('Employee');
    $contact = Contact::create(['name' => 'Shira Levi', 'created_by' => $user->id, 'search_key' => 'shiralevi']);
    expect(app(IdentityPanels::class)->forIdentity($user, $contact))->toBe([]);
    $user->givePermissionTo('identity-profile-test.view');
    $panels = app(IdentityPanels::class)->forIdentity($user, $contact);
    expect($panels)->toHaveCount(1)->and($panels[0]['endpoint'])->toBe('/identity-profile-test/'.$contact->id);
    $this->actingAs($user)->get('/people/contacts/'.$contact->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('identityPanels.0.id', 'identity-profile-test.customer'));
    $manifest = Installation::manifest();
    $manifest['modules'] = [];
    file_put_contents(config('core.manifest'), json_encode($manifest));
    expect(app(IdentityPanels::class)->forIdentity($user, $contact))->toBe([]);
});

it('requires the actual customer policy even when the panel permission is granted', function () {
    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $reader->assignRole('Employee');
    $reader->givePermissionTo('identity-profile-test.view');
    $company = Company::create(['name' => 'Kedem Studio', 'created_by' => $owner->id, 'search_key' => 'kedem']);
    expect(fn () => app(IdentityPanels::class)->forIdentity($reader, $company))->toThrow(AuthorizationException::class);
});

it('rejects unsafe or duplicate descriptors before permission-based display filtering', function (string $kind) {
    $user = User::factory()->create();
    $user->assignRole('Employee');
    $contact = Contact::create(['name' => 'Noa Shalev', 'created_by' => $user->id, 'search_key' => 'noashalev']);
    $normal = new IdentityPanel('identity-profile-test.customer', 'Extension', 'Example/Customer', '/example', 'identity-profile-test.view');
    $bad = match ($kind) {
        'component' => [new IdentityPanel('identity-profile-test.customer', 'Extension', '../Outside', '/example', 'identity-profile-test.view')],
        'endpoint' => [new IdentityPanel('identity-profile-test.customer', 'Extension', 'Example/Customer', '//outside.example.test', 'identity-profile-test.view')],
        'permission' => [new IdentityPanel('identity-profile-test.customer', 'Extension', 'Example/Customer', '/example', 'leads.view.own')],
        default => [$normal, $normal],
    };
    $property = new ReflectionProperty(Modules::class, 'installed');
    $installed = $property->getValue(app(Modules::class));
    $installed['identity-profile-test'] = new IdentityProfileTestModule($bad);
    $property->setValue(app(Modules::class), $installed);
    expect(fn () => app(IdentityPanels::class)->forIdentity($user, $contact))->toThrow(RuntimeException::class);
})->with(['component', 'endpoint', 'permission', 'duplicate']);
