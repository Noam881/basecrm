<?php

use App\Models\User;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Proposal;
use Awove\Core\Models\SignedContract;
use Awove\Core\Services\Leads;
use Awove\Core\Services\SignedContracts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
    Storage::fake('local');
    $GLOBALS['signed_contract_test_files'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['signed_contract_test_files'] ?? [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function signedContractFile(string $name, string $content, ?string $clientMime = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'basecrm-contract-test-');
    file_put_contents($path, $content);
    $GLOBALS['signed_contract_test_files'][] = $path;

    return new UploadedFile($path, $name, $clientMime, null, true);
}

function signedContractOwner(string $name = 'Shira Levi'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function signedContractLead(User $owner): Lead
{
    $contact = Contact::create(['name' => 'Noa Cohen', 'email' => 'noa@example.test', 'created_by' => $owner->id, 'search_key' => 'noacohen']);

    return app(Leads::class)->create($owner, ['contact_id' => $contact->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
}

function signedContractPdf(string $name = 'Signed contract.pdf', string $body = 'Fictional signed contract'): UploadedFile
{
    return signedContractFile($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% ".$body."\n%%EOF");
}

it('stores private immutable signed contract versions and exposes only safe metadata to authorized readers', function () {
    $owner = signedContractOwner();
    $lead = signedContractLead($owner);
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/signed-contracts', ['file' => signedContractPdf()])->assertRedirect('/leads/'.$lead->id);
    $first = SignedContract::sole();
    expect($first->lead_id)->toBe($lead->id)->and($first->version)->toBe(1)->and($first->created_by)->toBe($owner->id)
        ->and($first->original_name)->toBe('Signed contract.pdf')->and($first->mime_type)->toBe('application/pdf')
        ->and($first->private_path)->toStartWith('signed-contracts/'.$lead->id.'/')->and($first->toArray())->not->toHaveKeys(['private_path', 'sha256']);
    Storage::disk('local')->assertExists($first->private_path);
    expect(Storage::disk('local')->getVisibility($first->private_path))->toBe('private')
        ->and(hash('sha256', Storage::disk('local')->get($first->private_path)))->toBe($first->sha256);
    $this->post('/leads/'.$lead->id.'/signed-contracts', ['file' => signedContractPdf('Revised contract.pdf', 'Revised fictional signed contract'), 'version' => 1, 'created_by' => 999])->assertRedirect();
    $second = SignedContract::orderByDesc('version')->firstOrFail();
    expect($second->version)->toBe(2)->and($second->created_by)->toBe($owner->id)->and($second->private_path)->not->toBe($first->private_path)
        ->and(SignedContract::count())->toBe(2)->and($first->fresh()->original_name)->toBe('Signed contract.pdf')
        ->and(Activity::where('event', 'signed_contract.uploaded')->count())->toBe(2)->and(Proposal::count())->toBe(0);
    $this->getJson('/leads/'.$lead->id.'/signed-contracts')->assertOk()->assertJsonCount(2, 'signed_contracts')
        ->assertJsonPath('signed_contracts.0.version', 2)->assertJsonPath('signed_contracts.0.created_by.name', $owner->name)
        ->assertJsonPath('signed_contracts.0.download_url', '/leads/'.$lead->id.'/signed-contracts/'.$second->id.'/download')
        ->assertJsonMissingPath('signed_contracts.0.private_path')->assertJsonMissingPath('signed_contracts.0.sha256')
        ->assertJsonMissingPath('signed_contracts.0.created_by.email');
    $profile = $this->get('/leads/'.$lead->id)->assertOk();
    expect($profile->inertiaProps('signed_contracts.0.id'))->toBe($second->id)
        ->and($profile->inertiaProps('signed_contracts.0.created_by.id'))->toBe($owner->id)
        ->and($profile->inertiaProps('signed_contracts.0'))->not->toHaveKeys(['private_path', 'sha256']);
    $this->get('/storage/'.$first->private_path)->assertForbidden();
    $download = $this->get('/leads/'.$lead->id.'/signed-contracts/'.$first->id.'/download')->assertOk()
        ->assertDownload('Signed contract.pdf')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/octet-stream');
    expect($download->headers->get('Cache-Control'))->toContain('no-store')
        ->and($download->streamedContent())->toBe(Storage::disk('local')->get($first->private_path));
    expect(fn () => $first->update(['original_name' => 'Changed.pdf']))->toThrow(LogicException::class);
    expect(fn () => $first->delete())->toThrow(LogicException::class);
});

it('rechecks lead access on every list download and upload including ownership changes', function () {
    $owner = signedContractOwner();
    $other = signedContractOwner('Uri Ben David');
    $reader = signedContractOwner('Tamar Ben Ami');
    $reader->givePermissionTo('leads.view.all');
    $lead = signedContractLead($owner);
    $contract = app(SignedContracts::class)->upload($owner, $lead, ['file' => signedContractPdf()]);
    $url = '/leads/'.$lead->id.'/signed-contracts';
    $downloadUrl = $url.'/'.$contract->id.'/download';
    $this->get($url)->assertRedirect('/login');
    $this->get($downloadUrl)->assertRedirect('/login');
    $this->post($url, ['file' => signedContractPdf()])->assertRedirect('/login');
    $this->actingAs($other)->get($url)->assertForbidden();
    $this->get($downloadUrl)->assertForbidden();
    $this->post($url, ['file' => signedContractPdf()])->assertForbidden();
    $this->actingAs($reader)->get($url)->assertOk();
    $this->get($downloadUrl)->assertOk();
    $this->post($url, ['file' => signedContractPdf()])->assertForbidden();
    $owner->givePermissionTo('leads.assign');
    app(Leads::class)->update($owner, $lead->id, ['owner_id' => $other->id, 'version' => $lead->fresh()->version]);
    // A cached pre-transfer parent cannot preserve access for direct service callers.
    expect(fn () => app(SignedContracts::class)->forLead($owner, $lead))->toThrow(AuthorizationException::class);
    expect(fn () => app(SignedContracts::class)->download($owner, $lead, $contract))->toThrow(AuthorizationException::class);
    $this->actingAs($owner)->get($downloadUrl)->assertForbidden();
    $this->get($url)->assertForbidden();
    $this->actingAs($other)->get($downloadUrl)->assertOk();
    $other->forceFill(['active' => false])->save();
    $this->getJson($url)->assertForbidden();
    $this->getJson($downloadUrl)->assertUnauthorized();
    $this->postJson($url, ['file' => signedContractPdf()])->assertUnauthorized();
    expect(SignedContract::count())->toBe(1);
});

it('prevents cross-lead file binding and retains authorized historical downloads on archived leads', function () {
    $owner = signedContractOwner();
    $lead = signedContractLead($owner);
    $otherLead = signedContractLead($owner);
    $contract = app(SignedContracts::class)->upload($owner, $lead, ['file' => signedContractPdf()]);
    $this->actingAs($owner)->get('/leads/'.$otherLead->id.'/signed-contracts/'.$contract->id.'/download')->assertNotFound();
    $lead->update(['archived_at' => now()]);
    $this->get('/leads/'.$lead->id.'/signed-contracts')->assertOk();
    $this->get('/leads/'.$lead->id.'/signed-contracts/'.$contract->id.'/download')->assertOk();
    $this->post('/leads/'.$lead->id.'/signed-contracts', ['file' => signedContractPdf()])->assertForbidden();
    Storage::disk('local')->delete($contract->private_path);
    $this->get('/leads/'.$lead->id.'/signed-contracts/'.$contract->id.'/download')->assertNotFound();
    expect(SignedContract::count())->toBe(1);
});

it('rejects unsupported spoofed oversized or empty files before persisting any version', function (string $kind) {
    $owner = signedContractOwner();
    $lead = signedContractLead($owner);
    $file = match ($kind) {
        'missing' => null,
        'executable' => signedContractFile('Signed contract.php', '<?php echo "Fictional";'),
        'spoofed pdf' => signedContractFile('Signed contract.pdf', '<?php echo "Fictional";'),
        'wrong extension' => signedContractPdf('Signed contract.doc'),
        'archive' => UploadedFile::fake()->create('Signed contract.zip', 10, 'application/zip'),
        'oversized' => UploadedFile::fake()->create('Signed contract.pdf', 10241, 'application/pdf'),
        'empty' => signedContractFile('Signed contract.pdf', ''),
    };
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/signed-contracts', ['file' => $file])->assertSessionHasErrors('file');
    expect(SignedContract::count())->toBe(0)->and(Activity::where('event', 'signed_contract.uploaded')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['missing', 'executable', 'spoofed pdf', 'wrong extension', 'archive', 'oversized', 'empty']);

it('accepts Word document content types in addition to PDF', function (string $extension, string $mime) {
    $owner = signedContractOwner();
    $lead = signedContractLead($owner);
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/signed-contracts', ['file' => UploadedFile::fake()->create('Signed contract.'.$extension, 100, $mime)])->assertRedirect();
    expect(SignedContract::sole()->mime_type)->toBe($mime)->and(SignedContract::sole()->original_name)->toBe('Signed contract.'.$extension);
})->with([
    ['doc', 'application/msword'],
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
]);

it('accepts a real Word package while rejecting an ordinary zip renamed as a Word file', function () {
    $owner = signedContractOwner();
    $lead = signedContractLead($owner);
    $path = tempnam(sys_get_temp_dir(), 'crm-contract-');
    try {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Fictional signed contract</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $file = signedContractFile('Signed contract.docx', file_get_contents($path));
        $this->actingAs($owner)->post('/leads/'.$lead->id.'/signed-contracts', ['file' => $file])->assertRedirect();
        expect(SignedContract::sole()->mime_type)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('ordinary-text.txt', 'Fictional archive content.');
        $zip->close();
        $archive = signedContractFile('Archive.docx', file_get_contents($path));
        $this->post('/leads/'.$lead->id.'/signed-contracts', ['file' => $archive])->assertSessionHasErrors('file');
        expect(SignedContract::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    } finally {
        unlink($path);
    }
});

it('normalizes unsafe filenames without using them as private storage paths', function () {
    $owner = signedContractOwner();
    $lead = signedContractLead($owner);
    $contract = app(SignedContracts::class)->upload($owner, $lead, ['file' => signedContractPdf("../../Niva\r\nX-Header%22.pdf")]);
    expect($contract->original_name)->toBe('Niva_X-Header_22.pdf')
        ->and($contract->private_path)->not->toContain('Niva')->and($contract->private_path)->not->toContain('..');
    $this->actingAs($owner)->get('/leads/'.$lead->id.'/signed-contracts/'.$contract->id.'/download')->assertOk()->assertDownload('Niva_X-Header_22.pdf');
});

it('removes only the attempted private file when its database write fails', function () {
    $owner = signedContractOwner();
    $lead = signedContractLead($owner);
    $saved = app(SignedContracts::class)->upload($owner, $lead, ['file' => signedContractPdf()]);
    $service = Mockery::mock(Leads::class)->makePartial();
    $service->shouldReceive('log')->once()->andThrow(new RuntimeException('Simulated audit persistence failure.'));
    app()->instance(Leads::class, $service);
    expect(fn () => app(SignedContracts::class)->upload($owner, $lead, ['file' => signedContractPdf('Revision.pdf')]))->toThrow(RuntimeException::class, 'Simulated audit persistence failure.');
    expect(SignedContract::count())->toBe(1)->and(SignedContract::sole()->id)->toBe($saved->id)
        ->and(Storage::disk('local')->allFiles())->toBe([$saved->private_path]);
});
