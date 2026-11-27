<?php

use App\Models\User;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Document;
use Awove\Core\Services\Documents;
use Awove\Core\Services\Identities;
use Awove\Core\Services\Leads;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
    Storage::fake('local');
    $GLOBALS['identity_document_test_files'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['identity_document_test_files'] ?? [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function documentOwner(string $name = 'Shira Levi'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function documentIdentity(User $owner, string $type = 'contacts'): Contact|Company
{
    return app(Identities::class)->create($owner, $type, ['name' => $type === 'contacts' ? 'Noa Cohen' : 'Cohen Studio']);
}

function documentFile(string $name = 'מסמך מקורי.pdf', ?string $content = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'basecrm-document-test-');
    file_put_contents($path, $content ?? "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% Fictional client document.\n%%EOF");
    $GLOBALS['identity_document_test_files'][] = $path;

    return new UploadedFile($path, $name, null, null, true);
}

it('retains private original documents and exposes safe metadata and exact attachment bytes', function (string $type) {
    $owner = documentOwner();
    $identity = documentIdentity($owner, $type);
    $url = '/people/'.$type.'/'.$identity->id.'/documents';
    $file = documentFile();
    $bytes = file_get_contents($file->getRealPath());
    $this->actingAs($owner)->post($url, ['file' => $file, 'uploaded_by' => 999, 'private_path' => 'forged.pdf', 'contact_id' => 999])->assertRedirect('/people/'.$type.'/'.$identity->id);
    $first = Document::sole();
    expect($first->original_name)->toBe('מסמך מקורי.pdf')->and($first->uploaded_by)->toBe($owner->id)
        ->and($first->contact_id)->toBe($type === 'contacts' ? $identity->id : null)
        ->and($first->company_id)->toBe($type === 'companies' ? $identity->id : null)
        ->and($first->private_path)->toStartWith('documents/'.$type.'/'.$identity->id.'/')
        ->and($first->private_path)->not->toContain('מקורי')->and($first->mime_type)->toBe('application/pdf')
        ->and($first->sha256)->toBe(hash('sha256', $bytes))->and($first->size_bytes)->toBe(strlen($bytes))
        ->and($first->toArray())->not->toHaveKeys(['private_path', 'sha256']);
    expect(Storage::disk('local')->getVisibility($first->private_path))->toBe('private');
    $this->post($url, ['file' => documentFile('Another document.pdf')])->assertRedirect();
    $second = Document::latest('id')->firstOrFail();
    expect($first->private_path)->not->toBe($second->private_path)->and($first->fresh()->original_name)->toBe('מסמך מקורי.pdf');
    $metadata = $this->getJson($url)->assertOk()->assertJsonCount(2, 'documents')->assertJsonPath('can_upload', true)
        ->assertJsonPath('documents.0.id', $second->id)->assertJsonPath('documents.0.uploaded_by.name', $owner->name)
        ->assertJsonPath('documents.1.download_url', $url.'/'.$first->id.'/download')
        ->assertJsonMissingPath('documents.0.private_path')->assertJsonMissingPath('documents.0.sha256')
        ->assertJsonMissingPath('documents.0.uploaded_by.email');
    expect($metadata->headers->get('Cache-Control'))->toContain('no-store');
    $download = $this->get($url.'/'.$first->id.'/download')->assertOk()->assertDownload()
        ->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Content-Disposition'))->toContain("filename*=utf-8''".rawurlencode('מסמך מקורי.pdf'))
        ->and($download->headers->get('Cache-Control'))->toContain('no-store')->and($download->streamedContent())->toBe($bytes);
    $this->get('/storage/'.$first->private_path)->assertForbidden();
    expect(fn () => $first->update(['original_name' => 'Changed.pdf']))->toThrow(LogicException::class);
    expect(fn () => $first->delete())->toThrow(LogicException::class);
})->with(['contacts', 'companies']);

it('checks current identity permissions for every operation and distinguishes read-only access', function () {
    $owner = documentOwner();
    $reader = documentOwner('Uri Ben David');
    $stranger = documentOwner('Tamar Erez');
    $identity = documentIdentity($owner);
    $owner->givePermissionTo(['leads.assign', 'leads.view.all', 'leads.edit.all']);
    $lead = app(Leads::class)->create($owner, ['contact_id' => $identity->id, 'owner_id' => $reader->id, 'status_id' => 'new']);
    $document = app(Documents::class)->upload($owner, 'contacts', $identity->id, ['file' => documentFile()]);
    $url = '/people/contacts/'.$identity->id.'/documents';
    $download = $url.'/'.$document->id.'/download';
    $this->get($url)->assertRedirect('/login');
    $this->get($download)->assertRedirect('/login');
    $this->post($url, ['file' => documentFile()])->assertRedirect('/login');
    $this->actingAs($stranger)->getJson($url)->assertForbidden();
    $this->get($download)->assertForbidden();
    $this->post($url, ['file' => documentFile()])->assertForbidden();
    $this->actingAs($reader)->getJson($url)->assertOk()->assertJsonPath('can_upload', false);
    $this->get($download)->assertOk();
    $this->post($url, ['file' => documentFile()])->assertForbidden();
    app(Leads::class)->update($owner, $lead->id, ['owner_id' => $stranger->id, 'version' => $lead->fresh()->version]);
    $this->getJson($url)->assertForbidden();
    $this->get($download)->assertForbidden();
    $owner->forceFill(['active' => false])->save();
    expect(fn () => app(Documents::class)->upload($owner, 'contacts', $identity->id, ['file' => documentFile()]))->toThrow(HttpException::class);
    $this->actingAs($owner)->getJson($url)->assertForbidden();
    expect(Document::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toBe([$document->private_path]);
});

it('prevents cross-profile and cross-type file binding even when the identity IDs coincide', function () {
    $owner = documentOwner();
    $contact = documentIdentity($owner);
    $company = Company::create(['id' => $contact->id, 'name' => 'Cohen Studio', 'created_by' => $owner->id, 'search_key' => 'cohenstudio']);
    expect($contact->id)->toBe($company->id);
    $other = app(Identities::class)->create($owner, 'contacts', ['name' => 'Yael Levi']);
    $document = app(Documents::class)->upload($owner, 'contacts', $contact->id, ['file' => documentFile()]);
    $this->actingAs($owner)->getJson('/people/companies/'.$company->id.'/documents')->assertOk()->assertJsonCount(0, 'documents');
    $this->get('/people/companies/'.$company->id.'/documents/'.$document->id.'/download')->assertNotFound();
    $this->get('/people/contacts/'.$other->id.'/documents/'.$document->id.'/download')->assertNotFound();
    $this->get('/people/unknown/'.$contact->id.'/documents')->assertNotFound();
    Storage::disk('local')->delete($document->private_path);
    $this->get('/people/contacts/'.$contact->id.'/documents/'.$document->id.'/download')->assertNotFound();
    expect(Document::count())->toBe(1);
});

it('preserves an accessible lead context without accepting arbitrary return locations', function () {
    $owner = documentOwner();
    $identity = documentIdentity($owner);
    $lead = app(Leads::class)->create($owner, ['contact_id' => $identity->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
    $url = '/people/contacts/'.$identity->id.'/documents';
    $this->actingAs($owner)->post($url.'?from_lead='.$lead->id, ['file' => documentFile(), 'return_to' => 'https://untrusted.example'])->assertRedirect('/people/contacts/'.$identity->id.'?from_lead='.$lead->id);
    $this->post($url.'?from_lead=https://untrusted.example', ['file' => documentFile()])->assertSessionHasErrors('from_lead', null, 'identity_documents');
    $this->post($url.'?from_lead=999999', ['file' => documentFile()])->assertForbidden();
    expect(Document::count())->toBe(1);
});

it('returns invalid uploads to the canonical profile without a Referer after metadata requests', function (string $type) {
    $owner = documentOwner();
    $identity = documentIdentity($owner, $type);
    $profile = '/people/'.$type.'/'.$identity->id;
    $url = $profile.'/documents';
    $lead = app(Leads::class)->create($owner, [($type === 'contacts' ? 'contact_id' : 'company_id') => $identity->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
    $this->actingAs($owner)->get($url)->assertOk();
    // The browser fetch is XHR; retain coverage for an earlier non-XHR metadata GET too.
    $this->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $this->post($url, [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertRedirect($profile)->assertSessionHasErrors('file', null, 'identity_documents');
    $this->post($url.'?from_lead='.$lead->id, [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertRedirect($profile.'?from_lead='.$lead->id)->assertSessionHasErrors('file', null, 'identity_documents');
    $this->post($url.'?from_lead=https://untrusted.example', [], ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertRedirect($profile)->assertSessionHasErrors('from_lead', null, 'identity_documents');
    expect(Document::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['contacts', 'companies']);

it('rejects missing unsafe spoofed empty or oversized files without persisting bytes', function (string $kind) {
    $owner = documentOwner();
    $identity = documentIdentity($owner);
    $file = match ($kind) {
        'missing' => null,
        'executable' => documentFile('Client.php', '<?php echo "Fictional";'),
        'spoofed pdf' => documentFile('Client.pdf', '<?php echo "Fictional";'),
        'wrong extension' => documentFile('Client.doc'),
        'header injection' => documentFile("Client\r\nX-Header.pdf"),
        'oversized' => UploadedFile::fake()->create('Client.pdf', 102401, 'application/pdf'),
        'empty' => documentFile('Client.txt', ''),
    };
    $this->actingAs($owner)->post('/people/contacts/'.$identity->id.'/documents', ['file' => $file])->assertSessionHasErrors('file', null, 'identity_documents');
    expect(Document::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['missing', 'executable', 'spoofed pdf', 'wrong extension', 'header injection', 'oversized', 'empty']);

it('accepts common business document formats while matching their detected content types', function (string $extension, string $mime) {
    $owner = documentOwner();
    $identity = documentIdentity($owner);
    $this->actingAs($owner)->post('/people/contacts/'.$identity->id.'/documents', ['file' => UploadedFile::fake()->createWithContent('Client.'.$extension, 'Fictional business document bytes.')->mimeType($mime)])->assertRedirect()->assertSessionHasNoErrors();
    expect(Document::sole()->mime_type)->toBe($mime);
})->with([
    ['doc', 'application/msword'], ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ['xls', 'application/vnd.ms-excel'], ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    ['csv', 'text/plain'], ['txt', 'text/plain'], ['jpg', 'image/jpeg'], ['png', 'image/png'], ['gif', 'image/gif'], ['webp', 'image/webp'], ['zip', 'application/zip'],
]);

it('cleans only the new random private path after a database failure and retains earlier documents', function () {
    $owner = documentOwner();
    $identity = documentIdentity($owner);
    $saved = app(Documents::class)->upload($owner, 'contacts', $identity->id, ['file' => documentFile()]);
    $event = 'eloquent.creating: '.Document::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic private payload must not surface.'));
    try {
        expect(fn () => app(Documents::class)->upload($owner, 'contacts', $identity->id, ['file' => documentFile('Another.pdf')]))->toThrow(RuntimeException::class, 'The document could not be stored. Please try again.');
    } finally {
        Event::forget($event);
    }
    expect(Document::count())->toBe(1)->and(Document::sole()->id)->toBe($saved->id)
        ->and(Storage::disk('local')->allFiles())->toBe([$saved->private_path]);
});

it('requires exactly one valid parent and retains the existing identity data', function () {
    $owner = documentOwner();
    $contact = documentIdentity($owner);
    $company = documentIdentity($owner, 'companies');
    $before = [$contact->fresh()->getAttributes(), $company->fresh()->getAttributes()];
    $document = app(Documents::class)->upload($owner, 'contacts', $contact->id, ['file' => documentFile()]);
    expect([$contact->fresh()->getAttributes(), $company->fresh()->getAttributes()])->toBe($before);
    foreach ([['contact_id' => null, 'company_id' => null], ['contact_id' => $contact->id, 'company_id' => $company->id]] as $invalid) {
        expect(fn () => DB::transaction(fn () => DB::table('crm_documents')->insert([...$document->getAttributes(), 'id' => $document->id + 99, 'private_path' => 'documents/invalid/'.uniqid(), ...$invalid])))->toThrow(QueryException::class);
    }
    expect(Document::count())->toBe(1);
});

it('adds document storage around existing identities without altering their data', function () {
    $owner = documentOwner();
    $contact = documentIdentity($owner);
    $company = documentIdentity($owner, 'companies');
    $lead = app(Leads::class)->create($owner, ['contact_id' => $contact->id, 'company_id' => $company->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
    $before = [$contact->fresh()->getAttributes(), $company->fresh()->getAttributes(), $lead->fresh()->getAttributes()];
    // Recreate only this empty new table inside the isolated base_test transaction.
    Schema::drop('crm_documents');
    $migration = require base_path('packages/awove/crm-core/database/migrations/2026_11_27_001700_create_identity_documents.php');
    $migration->up();
    expect([$contact->fresh()->getAttributes(), $company->fresh()->getAttributes(), $lead->fresh()->getAttributes()])->toBe($before)
        ->and(Document::count())->toBe(0);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Restore a tested backup');
    app(Documents::class)->upload($owner, 'contacts', $contact->id, ['file' => documentFile()]);
    expect(Document::count())->toBe(1);
});

it('detects real office packages and rejects ordinary archives masquerading as office files', function () {
    $owner = documentOwner();
    $identity = documentIdentity($owner);
    $path = tempnam(sys_get_temp_dir(), 'basecrm-docx-');
    try {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Fictional client document</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $url = '/people/contacts/'.$identity->id.'/documents';
        $this->actingAs($owner)->post($url, ['file' => documentFile('Original document.docx', file_get_contents($path))])->assertRedirect()->assertSessionHasNoErrors();
        expect(Document::sole()->mime_type)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('ordinary-text.txt', 'Fictional archive content.');
        $zip->close();
        $this->post($url, ['file' => documentFile('Archive.docx', file_get_contents($path))])->assertSessionHasErrors('file', null, 'identity_documents');
        $this->post($url, ['file' => documentFile('Archive.zip', file_get_contents($path))])->assertRedirect()->assertSessionHasNoErrors();
        expect(Document::count())->toBe(2);
    } finally {
        unlink($path);
    }
});
