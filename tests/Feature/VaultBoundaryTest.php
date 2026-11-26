<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Vault\Cipher;
use Awove\Vault\Http\NoStore;
use Awove\Vault\Models\Item;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $manifest = Installation::manifest();
    $manifest['modules'] = ['vault'];
    $path = tempnam(sys_get_temp_dir(), 'vault-boundaries-');
    file_put_contents($path, json_encode($manifest));
    config(['core.manifest' => $path]);
    config(['vault.key_file' => null, 'vault.active_key' => 'boundary', 'vault.keys' => ['boundary' => base64_encode(random_bytes(32))]]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    $path = config('core.manifest');
    if (is_string($path) && str_starts_with(basename($path), 'vault-boundaries-')) {
        @unlink($path);
    }
});
function vaultBoundaryUser(): User
{
    $user = User::factory()->create(['name' => 'Avi Ben Ami']);
    $user->assignRole('Employee');
    $user->givePermissionTo('vault.list');

    return $user;
}

it('forces secret-bearing validation failures to JSON with no old input even without an Accept header', function () {
    $user = vaultBoundaryUser();
    $secret = 'FICTIONAL-NEVER-FLASH-'.Str::random(24);
    $response = $this->actingAs($user)->post('/vault/api/auth/enroll', ['invitation' => str_repeat('x', 64), 'password' => $secret, 'password_confirmation' => 'different', 'payload' => ['notes' => $secret]])->assertUnprocessable()->assertSessionMissing('_old_input');
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store', 'private')
        ->and($response->getContent())->not->toContain($secret);
});

it('suppresses raw debug exceptions and request-secret error logging on Vault paths', function () {
    $user = vaultBoundaryUser();
    $secret = 'FICTIONAL-PRIVATE-ERROR-'.Str::random(20);
    config(['app.debug' => true]);
    Log::spy();
    Route::middleware(['web', NoStore::class, 'auth', 'crm.module:vault'])->post('/vault/api/__boundary', function () use ($secret) {
        throw new RuntimeException($secret);
    });
    $response = $this->actingAs($user)->post('/vault/api/__boundary', ['payload' => ['notes' => $secret]])->assertStatus(500)->assertSessionMissing('_old_input');
    expect($response->getContent())->not->toContain($secret, 'trace', 'RuntimeException')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    Log::shouldNotHaveReceived('error');
});

it('keeps secure content and ciphertext out of initial HTML customer profiles and ordinary contact exports', function () {
    $manifest = Installation::manifest();
    $manifest['capabilities']['sales'] = false;
    $manifest['locale'] = 'he';
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $user = vaultBoundaryUser();
    $user->givePermissionTo('exports.view');
    $contact = Contact::create(['name' => 'Yael Ben Ami', 'email' => 'yael.boundary@example.test', 'created_by' => $user->id, 'search_key' => 'yael']);
    $secret = "FICTIONAL-PRIVATE-MARKER\n  preserve spaces  ";
    $id = (string) Str::uuid();
    $sealed = app(Cipher::class)->encrypt(json_encode(['fields' => [], 'notes' => $secret]), 'item:'.$id);
    Item::create(['id' => $id, 'installation_id' => Installation::manifest()['id'], 'contact_id' => $contact->id, 'title' => 'Fictional service login', 'type' => 'login', 'level' => 2, 'version' => 1, 'encrypted_payload' => $sealed['ciphertext'], 'key_version' => $sealed['key_version'], 'created_by' => $user->id, 'updated_by' => $user->id]);
    $this->actingAs($user);
    $this->get('/vault')->assertOk()->assertInertia(fn ($page) => $page->where('moduleNavigation.0.label', 'כספת'));
    foreach (['/vault', '/people/contacts/'.$contact->id] as $url) {
        $response = $this->get($url)->assertOk();
        expect($response->getContent())->not->toContain($secret, $sealed['ciphertext']);
    }
    $export = $this->get('/spreadsheets/export/contacts')->assertOk()->streamedContent();
    expect($export)->not->toContain($secret, $sealed['ciphertext'], 'Fictional service login');
    $this->post('/vault/api/items/'.$id.'/content')->assertStatus(423);
});
