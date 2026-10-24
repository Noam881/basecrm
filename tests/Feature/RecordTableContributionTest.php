<?php

use App\Models\User;
use Awove\Core\Access;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Modules;
use Awove\Core\Tables\Catalog;
use Awove\Core\Tables\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\RecordTableModule;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    app(Modules::class)->register(new RecordTableModule);
    $this->recordTableManifest = tempnam(sys_get_temp_dir(), 'base-crm-record-table-');
    $manifest = Installation::manifest();
    $manifest['modules'][] = 'fixture-records';
    file_put_contents($this->recordTableManifest, json_encode($manifest));
    config(['core.manifest' => $this->recordTableManifest]);
    $this->artisan('crm:install')->assertSuccessful();
    Route::middleware(['web', 'auth', 'verified'])->get('/record-table-fixture', function (Request $request) {
        $result = app(Query::class)->run(Access::identities($request->user(), Contact::class), 'fixture-records.contacts', $request);
        // A module controls domain DTO redaction; the table catalog controls its query/cell fields.
        $result['page']->through(fn ($record) => ['id' => $record->id, 'cells' => $record->getAttribute('cells')]);

        return response()->json($result);
    });
});

afterEach(function () {
    unlink($this->recordTableManifest);
});

function recordTableUser(bool $sensitive = false): User
{
    $user = User::factory()->create(['active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo('fixture-records.view');
    if ($sensitive) {
        $user->givePermissionTo('fixture-records.sensitive');
    }

    return $user;
}

function recordTableContact(User $user, string $name, string $email): Contact
{
    return Contact::create(['name' => $name, 'email' => $email, 'created_by' => $user->id, 'search_key' => mb_strtolower($name)]);
}

function recordTableEnable(string $path, bool $enabled): void
{
    $manifest = json_decode(file_get_contents($path), true);
    $manifest['modules'] = array_values(array_diff($manifest['modules'], ['fixture-records']));
    if ($enabled) {
        $manifest['modules'][] = 'fixture-records';
    }
    file_put_contents($path, json_encode($manifest));
}

it('discovers enabled module tables and reuses scoped search sort filter and export preparation', function () {
    $user = recordTableUser(true);
    recordTableContact($user, 'Shira Levi', 'shira@example.test');
    recordTableContact($user, 'Noam Erez', 'noam@example.test');
    recordTableContact(recordTableUser(), 'Yael Shalev', 'hidden@example.test');
    expect(app(Catalog::class)->tableIds())->toContain('fixture-records.contacts');
    $response = $this->actingAs($user)->getJson('/record-table-fixture?'.http_build_query(['sorts' => [['id' => 'name', 'direction' => 'asc']]]))->assertOk()->assertJsonPath('page.total', 2)->assertJsonPath('page.data.0.cells.name', 'Noam Erez');
    expect(array_column($response->json('columns'), 'id'))->toBe(['name', 'email']);
    $this->getJson('/record-table-fixture?q=hidden@example.test')->assertOk()->assertJsonPath('page.total', 0);
    $this->getJson('/record-table-fixture?'.http_build_query(['filters' => [['id' => 'name', 'op' => 'starts', 'value' => 'Shi']]]))->assertOk()->assertJsonPath('page.total', 1);
    $request = Request::create('/', 'GET', ['q' => 'shira@example.test', 'sorts' => [['id' => 'name', 'direction' => 'desc']]]);
    $request->setUserResolver(fn () => $user);
    $records = app(Query::class)->filtered(Access::identities($user, Contact::class), 'fixture-records.contacts', $request)->get();
    expect($records->pluck('name')->all())->toBe(['Shira Levi']);
});

it('persists module layouts without granting fields and preserves them across disable and reenable', function () {
    $user = recordTableUser(true);
    recordTableContact($user, 'Shira Levi', 'shira@example.test');
    $payload = ['table' => 'fixture-records.contacts', 'columns' => ['email', 'name'], 'widths' => ['email' => 320], 'pinned' => ['email'], 'views' => [['id' => 'mail', 'name' => 'Email view', 'columns' => ['email'], 'state' => ['sorts' => [['id' => 'email', 'direction' => 'asc']]]]]];
    $this->actingAs($user)->postJson('/preferences', $payload)->assertOk();
    $preferences = $user->fresh()->display_preferences;
    $this->getJson('/record-table-fixture')->assertOk()->assertJsonPath('preferences.columns', ['email', 'name'])->assertJsonPath('preferences.widths.email', 320)->assertJsonPath('preferences.pinned', ['email']);
    recordTableEnable($this->recordTableManifest, false);
    expect(app(Catalog::class)->tableIds())->not->toContain('fixture-records.contacts');
    $this->getJson('/record-table-fixture')->assertNotFound();
    $this->postJson('/preferences', $payload)->assertUnprocessable()->assertJsonValidationErrors('table');
    expect($user->fresh()->display_preferences)->toBe($preferences);
    recordTableEnable($this->recordTableManifest, true);
    $this->getJson('/record-table-fixture')->assertOk()->assertJsonPath('preferences.columns', ['email', 'name']);
});

it('denies direct preference and query access after permission loss and removes unavailable defaults views widths and pins', function () {
    $user = recordTableUser(true);
    recordTableContact($user, 'Shira Levi', 'private-mail@example.test');
    $this->actingAs($user)->postJson('/preferences', ['table' => 'fixture-records.contacts', 'columns' => ['email'], 'widths' => ['email' => 320], 'pinned' => ['email'], 'views' => [['id' => 'private', 'name' => 'Private field', 'columns' => ['email'], 'state' => ['filters' => [['id' => 'email', 'op' => 'contains', 'value' => 'private']]]]]])->assertOk();
    $user->revokePermissionTo('fixture-records.sensitive');
    $response = $this->getJson('/record-table-fixture')->assertOk()->assertJsonPath('defaultColumns', ['name'])->assertJsonPath('preferences.columns', ['name'])->assertJsonPath('preferences.views.0.columns', ['name'])->assertJsonPath('preferences.views.0.state.filters', [])->assertJsonPath('preferences.pinned', []);
    expect($response->json('preferences.widths'))->toBe([])->and(array_column($response->json('columns'), 'id'))->toBe(['name']);
    expect($response->getContent())->not->toContain('private-mail@example.test');
    $this->getJson('/record-table-fixture?q=private-mail@example.test')->assertOk()->assertJsonPath('page.total', 0);
    $this->postJson('/preferences', ['table' => 'fixture-records.contacts', 'columns' => ['email']])->assertUnprocessable();
    $user->revokePermissionTo('fixture-records.view');
    $this->getJson('/record-table-fixture')->assertForbidden();
    $this->postJson('/preferences', ['table' => 'fixture-records.contacts', 'columns' => ['name']])->assertForbidden();
});

it('rejects unavailable and SQL-shaped fields for dynamic table searches sorts filters and saved views', function () {
    $user = recordTableUser();
    $this->actingAs($user);
    foreach ([['sorts' => [['id' => 'email', 'direction' => 'asc']]], ['sorts' => [['id' => 'name; SELECT 1', 'direction' => 'desc']]], ['filters' => [['id' => 'email', 'op' => 'contains', 'value' => 'secret']]], ['sorts' => [['id' => 'name', 'direction' => 'asc NULLS LAST']]]] as $query) {
        $this->getJson('/record-table-fixture?'.http_build_query($query))->assertUnprocessable();
    }
    $this->postJson('/preferences', ['table' => 'fixture-records.contacts', 'columns' => ['name'], 'views' => [['id' => 'v', 'name' => 'Hidden sort', 'columns' => ['name'], 'state' => ['sorts' => [['id' => 'email', 'direction' => 'asc']]]]]])->assertUnprocessable();
    $this->postJson('/preferences', ['table' => 'absent.records', 'columns' => ['name']])->assertUnprocessable()->assertJsonValidationErrors('table');
});

it('rejects contributions that try to take another module table namespace', function () {
    app(Modules::class)->register(new class extends RecordTableModule
    {
        public function id(): string
        {
            return 'invalid-records';
        }

        public function tableIds(): array
        {
            return ['fixture-records.contacts'];
        }
    });
    $manifest = json_decode(file_get_contents($this->recordTableManifest), true);
    $manifest['modules'][] = 'invalid-records';
    file_put_contents($this->recordTableManifest, json_encode($manifest));
    expect(fn () => app(Catalog::class)->tableIds())->toThrow(RuntimeException::class, 'Module table IDs must be unique and use the owning module namespace.');
});
