<?php

use App\Models\User;
use Awove\Core\Models\Activity;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Proposal;
use Awove\Core\Services\Leads;
use Awove\Core\Services\Proposals;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->artisan('crm:install')->assertSuccessful();
    Storage::fake('local');
    $GLOBALS['proposal_test_files'] = [];
});

afterEach(function () {
    foreach ($GLOBALS['proposal_test_files'] ?? [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

function proposalFile(string $name, string $content, ?string $clientMime = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'basecrm-proposal-test-');
    file_put_contents($path, $content);
    $GLOBALS['proposal_test_files'][] = $path;

    return new UploadedFile($path, $name, $clientMime, null, true);
}

function proposalOwner(string $name = 'Shira Levi'): User
{
    $user = User::factory()->create(['name' => $name, 'active' => true]);
    $user->assignRole('Employee');

    return $user;
}

function proposalLead(User $owner): Lead
{
    $contact = Contact::create(['name' => 'Noa Cohen', 'email' => 'noa@example.test', 'created_by' => $owner->id, 'search_key' => 'noacohen']);

    return app(Leads::class)->create($owner, ['contact_id' => $contact->id, 'owner_id' => $owner->id, 'status_id' => 'new']);
}

function proposalPdf(string $name = 'Proposal.pdf', string $body = 'Fictional proposal'): UploadedFile
{
    return proposalFile($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n% ".$body."\n%%EOF");
}

it('stores private immutable proposal versions and exposes only safe metadata to authorized readers', function () {
    $owner = proposalOwner();
    $lead = proposalLead($owner);
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/proposals', ['file' => proposalPdf()])->assertRedirect('/leads/'.$lead->id);
    $first = Proposal::sole();
    expect($first->lead_id)->toBe($lead->id)->and($first->version)->toBe(1)->and($first->uploaded_by)->toBe($owner->id)
        ->and($first->original_name)->toBe('Proposal.pdf')->and($first->mime_type)->toBe('application/pdf')
        ->and($first->private_path)->toStartWith('proposals/'.$lead->id.'/')->and($first->toArray())->not->toHaveKeys(['private_path', 'sha256']);
    Storage::disk('local')->assertExists($first->private_path);
    expect(Storage::disk('local')->getVisibility($first->private_path))->toBe('private')
        ->and(hash('sha256', Storage::disk('local')->get($first->private_path)))->toBe($first->sha256);
    $this->post('/leads/'.$lead->id.'/proposals', ['file' => proposalPdf('Updated proposal.pdf', 'Revised fictional proposal'), 'version' => 1, 'uploaded_by' => 999])->assertRedirect();
    $second = Proposal::orderByDesc('version')->firstOrFail();
    expect($second->version)->toBe(2)->and($second->uploaded_by)->toBe($owner->id)->and($second->private_path)->not->toBe($first->private_path)
        ->and(Proposal::count())->toBe(2)->and($first->fresh()->original_name)->toBe('Proposal.pdf')
        ->and(Activity::where('event', 'proposal.uploaded')->count())->toBe(2);
    $this->getJson('/leads/'.$lead->id.'/proposals')->assertOk()->assertJsonCount(2, 'proposals')
        ->assertJsonPath('proposals.0.version', 2)->assertJsonPath('proposals.0.uploaded_by.name', $owner->name)
        ->assertJsonPath('proposals.0.download_url', '/leads/'.$lead->id.'/proposals/'.$second->id.'/download')
        ->assertJsonMissingPath('proposals.0.private_path')->assertJsonMissingPath('proposals.0.sha256');
    $this->get('/storage/'.$first->private_path)->assertForbidden();
    $download = $this->get('/leads/'.$lead->id.'/proposals/'.$first->id.'/download')->assertOk()
        ->assertDownload('Proposal.pdf')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/octet-stream');
    expect($download->headers->get('Cache-Control'))->toContain('no-store')
        ->and($download->streamedContent())->toBe(Storage::disk('local')->get($first->private_path));
    expect(fn () => $first->update(['original_name' => 'Changed.pdf']))->toThrow(LogicException::class);
    expect(fn () => $first->delete())->toThrow(LogicException::class);
});

it('rechecks lead access on every list download and upload including ownership changes', function () {
    $owner = proposalOwner();
    $other = proposalOwner('Uri Ben David');
    $reader = proposalOwner('Tamar Ben Ami');
    $reader->givePermissionTo('leads.view.all');
    $lead = proposalLead($owner);
    $proposal = app(Proposals::class)->upload($owner, $lead, ['file' => proposalPdf()]);
    $url = '/leads/'.$lead->id.'/proposals';
    $downloadUrl = $url.'/'.$proposal->id.'/download';
    $this->get($url)->assertRedirect('/login');
    $this->get($downloadUrl)->assertRedirect('/login');
    $this->post($url, ['file' => proposalPdf()])->assertRedirect('/login');
    $this->actingAs($other)->get($url)->assertForbidden();
    $this->get($downloadUrl)->assertForbidden();
    $this->post($url, ['file' => proposalPdf()])->assertForbidden();
    $this->actingAs($reader)->get($url)->assertOk();
    $this->get($downloadUrl)->assertOk();
    $this->post($url, ['file' => proposalPdf()])->assertForbidden();
    $owner->givePermissionTo('leads.assign');
    app(Leads::class)->update($owner, $lead->id, ['owner_id' => $other->id, 'version' => $lead->fresh()->version]);
    $this->actingAs($owner)->get($downloadUrl)->assertForbidden();
    $this->get($url)->assertForbidden();
    $this->actingAs($other)->get($downloadUrl)->assertOk();
    $other->forceFill(['active' => false])->save();
    $this->getJson($url)->assertForbidden();
    $this->getJson($downloadUrl)->assertUnauthorized();
    $this->postJson($url, ['file' => proposalPdf()])->assertUnauthorized();
    expect(Proposal::count())->toBe(1);
});

it('prevents cross-lead file binding and retains authorized historical downloads on archived leads', function () {
    $owner = proposalOwner();
    $lead = proposalLead($owner);
    $otherLead = proposalLead($owner);
    $proposal = app(Proposals::class)->upload($owner, $lead, ['file' => proposalPdf()]);
    $this->actingAs($owner)->get('/leads/'.$otherLead->id.'/proposals/'.$proposal->id.'/download')->assertNotFound();
    $lead->update(['archived_at' => now()]);
    $this->get('/leads/'.$lead->id.'/proposals')->assertOk();
    $this->get('/leads/'.$lead->id.'/proposals/'.$proposal->id.'/download')->assertOk();
    $this->post('/leads/'.$lead->id.'/proposals', ['file' => proposalPdf()])->assertForbidden();
    Storage::disk('local')->delete($proposal->private_path);
    $this->get('/leads/'.$lead->id.'/proposals/'.$proposal->id.'/download')->assertNotFound();
    expect(Proposal::count())->toBe(1);
});

it('rejects unsupported spoofed oversized or empty files before persisting any version', function (string $kind) {
    $owner = proposalOwner();
    $lead = proposalLead($owner);
    $file = match ($kind) {
        'missing' => null,
        'executable' => proposalFile('Proposal.php', '<?php echo "Fictional";'),
        'spoofed pdf' => proposalFile('Proposal.pdf', '<?php echo "Fictional";'),
        'wrong extension' => proposalPdf('Proposal.doc'),
        'archive' => UploadedFile::fake()->create('Proposal.zip', 10, 'application/zip'),
        'oversized' => UploadedFile::fake()->create('Proposal.pdf', 10241, 'application/pdf'),
        'empty' => proposalFile('Proposal.pdf', ''),
    };
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/proposals', ['file' => $file])->assertSessionHasErrors('file');
    expect(Proposal::count())->toBe(0)->and(Activity::where('event', 'proposal.uploaded')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['missing', 'executable', 'spoofed pdf', 'wrong extension', 'archive', 'oversized', 'empty']);

it('accepts Word document content types in addition to PDF', function (string $extension, string $mime) {
    $owner = proposalOwner();
    $lead = proposalLead($owner);
    $this->actingAs($owner)->post('/leads/'.$lead->id.'/proposals', ['file' => UploadedFile::fake()->create('Proposal.'.$extension, 100, $mime)])->assertRedirect();
    expect(Proposal::sole()->mime_type)->toBe($mime)->and(Proposal::sole()->original_name)->toBe('Proposal.'.$extension);
})->with([
    ['doc', 'application/msword'],
    ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
]);

it('accepts a real Word package while rejecting an ordinary zip renamed as a Word file', function () {
    $owner = proposalOwner();
    $lead = proposalLead($owner);
    $path = tempnam(sys_get_temp_dir(), 'crm-proposal-');
    try {
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Fictional proposal</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $file = proposalFile('Proposal.docx', file_get_contents($path));
        $this->actingAs($owner)->post('/leads/'.$lead->id.'/proposals', ['file' => $file])->assertRedirect();
        expect(Proposal::sole()->mime_type)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('ordinary-text.txt', 'Fictional archive content.');
        $zip->close();
        $archive = proposalFile('Archive.docx', file_get_contents($path));
        $this->post('/leads/'.$lead->id.'/proposals', ['file' => $archive])->assertSessionHasErrors('file');
        expect(Proposal::count())->toBe(1)->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    } finally {
        unlink($path);
    }
});

it('normalizes unsafe filenames without using them as private storage paths', function () {
    $owner = proposalOwner();
    $lead = proposalLead($owner);
    $proposal = app(Proposals::class)->upload($owner, $lead, ['file' => proposalPdf("../../Niva\r\nX-Header%22.pdf")]);
    expect($proposal->original_name)->toBe('Niva_X-Header_22.pdf')
        ->and($proposal->private_path)->not->toContain('Niva')->and($proposal->private_path)->not->toContain('..');
    $this->actingAs($owner)->get('/leads/'.$lead->id.'/proposals/'.$proposal->id.'/download')->assertOk()->assertDownload('Niva_X-Header_22.pdf');
});

it('removes only the attempted private file when its database write fails', function () {
    $owner = proposalOwner();
    $lead = proposalLead($owner);
    $saved = app(Proposals::class)->upload($owner, $lead, ['file' => proposalPdf()]);
    $service = Mockery::mock(Leads::class)->makePartial();
    $service->shouldReceive('log')->once()->andThrow(new RuntimeException('Simulated audit persistence failure.'));
    app()->instance(Leads::class, $service);
    expect(fn () => app(Proposals::class)->upload($owner, $lead, ['file' => proposalPdf('Revision.pdf')]))->toThrow(RuntimeException::class, 'Simulated audit persistence failure.');
    expect(Proposal::count())->toBe(1)->and(Proposal::sole()->id)->toBe($saved->id)
        ->and(Storage::disk('local')->allFiles())->toBe([$saved->private_path]);
});
