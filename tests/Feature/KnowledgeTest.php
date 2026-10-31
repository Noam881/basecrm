<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkReferences;
use Awove\Core\Services\WorkReminders;
use Awove\Knowledge\Access;
use Awove\Knowledge\DueReminders;
use Awove\Knowledge\Instructions;
use Awove\Knowledge\KnowledgeModule;
use Awove\Knowledge\Models\Entry;
use Awove\Knowledge\Models\KnowledgeDocument;
use Awove\Knowledge\Models\KnowledgeHistory;
use Awove\Knowledge\Models\Renewal;
use Awove\Knowledge\Models\RenewalEvent;
use Awove\Knowledge\Records;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->knowledgeManifest = tempnam(sys_get_temp_dir(), 'crm-knowledge-');
    $this->knowledgeData = Installation::manifest();
    $this->knowledgeData['modules'] = ['knowledge'];
    $this->knowledgeData['capabilities']['sales'] = false;
    file_put_contents($this->knowledgeManifest, json_encode($this->knowledgeData));
    config(['core.manifest' => $this->knowledgeManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});
afterEach(function () {
    if (is_file($this->knowledgeManifest)) {
        unlink($this->knowledgeManifest);
    }
});
function knowledgeStaff(bool $all = false, bool $documents = false, bool $references = false): User
{
    $u = User::factory()->create(['name' => 'Shira Cohen']);
    $u->assignRole('Employee');
    $p = [];
    foreach (['library', 'renewals', 'systems'] as $s) {
        foreach (['view.own', 'create', 'edit.own', 'archive', 'export'] as $a) {
            $p[] = 'knowledge.'.$s.'.'.$a;
        }if ($all) {
            foreach (['view.all', 'edit.all', 'assign'] as $a) {
                $p[] = 'knowledge.'.$s.'.'.$a;
            }
        }
    }if ($documents) {
        $p = [...$p, 'knowledge.documents.view', 'knowledge.documents.manage'];
    }if ($references) {
        $p = [...$p, 'knowledge.systems.references.view', 'knowledge.systems.references.edit'];
    }$u->givePermissionTo($p);

    return $u;
}
function knowledgeEntry(User $u, array $change = []): Entry
{
    return app(Records::class)->save($u, 'library', [...['kind' => 'procedure', 'title' => 'Prepare customer handover', 'category' => 'Delivery', 'relevant_role' => 'Coordinator', 'revision' => '1.0', 'revised_on' => '2026-10-20', 'updated_on' => '2026-10-20', 'status' => 'current', 'owner_id' => $u->id], ...$change]);
}
function knowledgeRenewal(User $u, array $change = []): Renewal
{
    return app(Records::class)->save($u, 'renewals', [...['name' => 'Professional insurance', 'provider' => 'Olive Cover', 'expires_on' => now(Installation::settings()['timezone'])->addDays(10)->toDateString(), 'remind_on' => now(Installation::settings()['timezone'])->toDateString(), 'status' => 'pending', 'owner_id' => $u->id], ...$change]);
}
function knowledgeModules($test, array $modules): void
{
    $test->knowledgeData['modules'] = $modules;
    file_put_contents($test->knowledgeManifest, json_encode($test->knowledgeData));
}
function knowledgeRoutine(User $u)
{
    $u->givePermissionTo(['executive.view.own', 'executive.create', 'executive.edit.own', 'executive.archive']);

    return app(Routines::class)->create($u, 'executive', 'routines', ['name' => 'Review delivery instructions', 'owner_id' => $u->id, 'frequency' => 'Every working week', 'next_due_on' => now()->toDateString(), 'checklist' => []]);
}

it('persists procedures materials and training independently with editable roles topics and revision dates', function () {
    $u = knowledgeStaff();
    $this->actingAs($u)->post('/knowledge/library', ['kind' => 'procedure', 'title' => 'Prepare customer handover', 'category' => 'Delivery', 'relevant_role' => 'Coordinator', 'revision' => '1.0', 'revised_on' => '2026-10-20', 'status' => 'current', 'owner_id' => $u->id])->assertRedirect();
    $entry = Entry::sole();
    $this->patch('/knowledge/library/'.$entry->id, ['version' => $entry->version, 'title' => 'Review customer handover', 'revision' => '1.1', 'updated_on' => '2026-10-25'])->assertRedirect();
    $this->get('/knowledge/library/'.$entry->id)->assertInertia(fn (Assert $p) => $p->where('currentOwnerId', $u->id)->where('record.title', 'Review customer handover')->where('record.revision', '1.1')->where('record.revised_on', '2026-10-20')->where('record.updated_on', '2026-10-25'));
    knowledgeEntry($u, ['kind' => 'material', 'title' => 'Proposal introduction', 'purpose' => 'Introduce agreed services', 'use_classification' => 'Sales', 'revision' => null]);
    knowledgeEntry($u, ['kind' => 'training', 'title' => 'Welcome briefing', 'relevant_role' => 'Coordinator', 'revision' => null]);
    $this->get('/knowledge?section=library&role=Coordinator&kind=training&q=Welcome')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('table.page.data.0.title', 'Welcome briefing'));
    expect(KnowledgeHistory::where('entry_id', $entry->id)->count())->toBe(2)->and(Entry::count())->toBe(3);
});
it('requires meaningful manual metadata and stable versioning without overwriting configured status labels', function () {
    $u = knowledgeStaff();
    $this->actingAs($u)->postJson('/knowledge/library', ['kind' => 'procedure', 'title' => 'Review handover', 'status' => 'current', 'owner_id' => $u->id])->assertUnprocessable()->assertJsonValidationErrors('revision');
    $this->postJson('/knowledge/library', ['kind' => 'training', 'title' => 'Briefing', 'status' => 'draft', 'owner_id' => $u->id])->assertUnprocessable()->assertJsonValidationErrors('relevant_role');
    $this->postJson('/knowledge/library', ['kind' => 'material', 'title' => 'Proposal', 'status' => 'draft', 'owner_id' => $u->id])->assertUnprocessable()->assertJsonValidationErrors(['purpose', 'use_classification']);
    $entry = knowledgeEntry($u, ['revision' => null, 'revised_on' => '2026-10-20']);
    $this->knowledgeData['knowledge_library_statuses'] = ['current' => 'Published procedure'];
    file_put_contents($this->knowledgeManifest, json_encode($this->knowledgeData));
    $this->artisan('crm:install')->assertSuccessful();
    $this->get('/knowledge/library/'.$entry->id)->assertInertia(fn (Assert $p) => $p->where('statuses.current', 'Published procedure')->where('record.status', 'current'));
    $this->patchJson('/knowledge/library/'.$entry->id, ['version' => $entry->version, 'kind' => 'training'])->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->patchJson('/knowledge/library/'.$entry->id, ['version' => $entry->version + 1, 'title' => 'Wrong'])->assertConflict();
    expect($entry->fresh()->title)->toBe('Prepare customer handover');
});
it('enforces own all assignment and unassigned scopes with historical inactive owners retained', function () {
    $u = knowledgeStaff();
    $other = knowledgeStaff();
    $entry = knowledgeEntry($other);
    $this->actingAs($u)->get('/knowledge/library/'.$entry->id)->assertNotFound();
    $this->patchJson('/knowledge/library/'.$entry->id, ['version' => 1, 'title' => 'Hidden'])->assertNotFound();
    $this->postJson('/knowledge/library', ['kind' => 'procedure', 'title' => 'Unassigned', 'status' => 'draft', 'owner_id' => null])->assertForbidden();
    $u->givePermissionTo(['knowledge.library.view.all', 'knowledge.library.edit.all', 'knowledge.library.assign']);
    $record = knowledgeEntry($u, ['owner_id' => null, 'status' => 'draft']);
    $this->get('/knowledge?section=library&view=missing-owner')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1));
    $this->patch('/knowledge/library/'.$record->id, ['version' => 1, 'owner_id' => $other->id])->assertRedirect();
    $other->forceFill(['active' => false])->save();
    $this->patch('/knowledge/library/'.$record->id, ['version' => 2, 'title' => 'Retained owner'])->assertRedirect();
    expect($record->fresh()->owner_id)->toBe($other->id);
});
it('refreshes cached actor grants and rejects writes after revocation or deactivation', function () {
    $u = knowledgeStaff();
    $record = knowledgeEntry($u);
    $cached = $u->fresh();
    expect($cached->can('knowledge.library.edit.own'))->toBeTrue();
    $u->revokePermissionTo('knowledge.library.edit.own');
    expect(fn () => app(Records::class)->save($cached, 'library', ['version' => 1, 'title' => 'Blocked'], $record->id))->toThrow(HttpException::class);
    $u->revokePermissionTo('knowledge.library.view.own');
    expect(Access::query($cached, 'library')->count())->toBe(0);
    $u->forceFill(['active' => false])->save();
    expect((new KnowledgeModule)->widgets($cached))->toBe([]);
});
it('keeps document links private immutable retained and scoped to their parent record', function () {
    $u = knowledgeStaff(false, true);
    $entry = knowledgeEntry($u);
    $doc = app(Records::class)->document($u, 'library', $entry->id, ['name' => 'Handover instructions', 'url' => 'https://example.test/private-handover.pdf']);
    $this->actingAs($u)->get('/knowledge/documents/'.$doc->id)->assertRedirect('https://example.test/private-handover.pdf');
    $this->get('/knowledge/library/'.$entry->id)->assertInertia(fn (Assert $p) => $p->where('documents.0.href', '/knowledge/documents/'.$doc->id)->missing('documents.0.url'));
    expect(fn () => $doc->update(['url' => 'https://example.test/replacement.pdf']))->toThrow(LogicException::class);
    $other = knowledgeStaff(false, true);
    $this->actingAs($other)->get('/knowledge/documents/'.$doc->id)->assertNotFound();
    $this->actingAs($u);
    $u->revokePermissionTo('knowledge.documents.view');
    $this->get('/knowledge/documents/'.$doc->id)->assertForbidden();
    $response = $this->get('/knowledge/library/'.$entry->id)->assertOk();
    expect($response->inertiaProps('documents'))->toBe([])->and($response->getContent())->not->toContain('private-handover.pdf', 'Handover instructions');
    $this->getJson('/knowledge?section=library&view=missing-document')->assertUnprocessable();
    $u->givePermissionTo('knowledge.documents.view');
    app(Records::class)->archiveDocument($u, $doc->id, ['version' => 1]);
    expect($doc->fresh()->getAttribute('url'))->toBe('https://example.test/private-handover.pdf')->and($doc->fresh()->archived_at)->not->toBeNull();
    $this->get('/knowledge/documents/'.$doc->id)->assertRedirect('https://example.test/private-handover.pdf');
    expect(fn () => $doc->delete())->toThrow(LogicException::class);
});
it('rolls back invalid initial document writes and rejects unsafe URLs', function () {
    $u = knowledgeStaff(false, true);
    $this->actingAs($u);
    foreach (['http://example.test/a.pdf', 'javascript:alert(1)', 'https://name:password@example.test/a.pdf', 'https://example.test/white space.pdf'] as $url) {
        $this->postJson('/knowledge/library', ['kind' => 'procedure', 'title' => 'Invalid document', 'status' => 'draft', 'owner_id' => $u->id, 'document_name' => 'Instructions', 'document_url' => $url])->assertUnprocessable();
    }expect(Entry::count())->toBe(0)->and(KnowledgeHistory::count())->toBe(0)->and(KnowledgeDocument::count())->toBe(0);
});
it('separately protects system item references and preserves hidden values on ordinary edits', function () {
    $u = knowledgeStaff(false, false, true);
    $this->actingAs($u)->post('/knowledge/systems', ['name' => 'Team workspace', 'owner_id' => $u->id, 'login_url' => 'https://example.test/login', 'password_manager_reference' => 'Business vault / Team workspace #42'])->assertRedirect();
    $id = DB::table('knowledge_systems')->sole()->id;
    $this->get('/knowledge/systems/'.$id.'/login')->assertRedirect('https://example.test/login');
    $u->revokePermissionTo(['knowledge.systems.references.view', 'knowledge.systems.references.edit']);
    $response = $this->get('/knowledge/systems/'.$id)->assertOk();
    expect($response->getContent())->not->toContain('Business vault / Team workspace', 'https://example.test/login');
    $this->patch('/knowledge/systems/'.$id, ['version' => 1, 'name' => 'Team workspace record'])->assertRedirect();
    expect(DB::table('knowledge_systems')->find($id)->password_manager_reference)->toBe('Business vault / Team workspace #42');
    $this->patchJson('/knowledge/systems/'.$id, ['version' => 2, 'password_manager_reference' => null])->assertForbidden();
    $this->get('/knowledge/systems/'.$id.'/login')->assertForbidden();
    $this->get('/knowledge?section=systems&q=Business')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 0));
    $this->getJson('/knowledge?'.http_build_query(['section' => 'systems', 'sorts' => [['id' => 'password_manager_reference', 'direction' => 'asc']]]))->assertUnprocessable();
    $csv = $this->get('/knowledge/export?section=systems')->assertOk()->streamedContent();
    expect($csv)->toContain('Team workspace record')->not->toContain('Business vault', 'login_url', 'password_manager_reference');
});
it('rejects credential-shaped system input without building a credential vault', function () {
    $u = knowledgeStaff(false, false, true);
    $this->actingAs($u);
    foreach ([['password' => 'do-not-store'], ['api_key' => 'do-not-store'], ['password_manager_reference' => 'password: do-not-store'], ['password_manager_reference' => 'sk-secret'], ['password_manager_reference' => 'https://vault.example.test/token'], ['login_url' => 'https://user:pass@example.test/login'], ['login_url' => 'https://example.test/login?token=do-not-store'], ['login_url' => 'https://example.test/login#secret']] as $bad) {
        $this->postJson('/knowledge/systems', ['name' => 'Team workspace', 'owner_id' => $u->id, ...$bad])->assertUnprocessable();
    }expect(DB::table('knowledge_systems')->count())->toBe(0);
});
it('retains dated renewal occurrences and schedules only explicitly entered next dates', function () {
    $u = knowledgeStaff();
    $renewal = knowledgeRenewal($u);
    $expiry = $renewal->toArray()['expires_on'];
    $today = now(Installation::settings()['timezone'])->toDateString();
    $next = now(Installation::settings()['timezone'])->addYear()->toDateString();
    $this->actingAs($u)->post('/knowledge/renewals/'.$renewal->id.'/renew', ['version' => 1, 'renewed_on' => $today, 'next_expiry_on' => $next, 'next_remind_on' => $next, 'summary' => 'Renewed policy manually.'])->assertRedirect();
    expect($renewal->fresh()->toArray())->toMatchArray(['expires_on' => $next, 'remind_on' => $next, 'status' => 'pending', 'version' => 2]);
    $event = RenewalEvent::sole();
    expect($event->toArray())->toMatchArray(['previous_expiry_on' => $expiry, 'renewed_on' => $today, 'next_expiry_on' => $next]);
    $this->postJson('/knowledge/renewals/'.$renewal->id.'/renew', ['version' => 1, 'renewed_on' => $today])->assertConflict();
    expect(RenewalEvent::count())->toBe(1);
    $this->post('/knowledge/renewals/'.$renewal->id.'/renew', ['version' => 2, 'renewed_on' => $today, 'summary' => 'No further renewal needed.'])->assertRedirect();
    expect($renewal->fresh()->status)->toBe('renewed')->and($renewal->fresh()->remind_on)->toBeNull()->and(RenewalEvent::count())->toBe(2);
    $this->postJson('/knowledge/renewals/'.$renewal->id.'/renew', ['version' => 3, 'renewed_on' => $today])->assertConflict();
    expect(fn () => $event->update(['summary' => 'Overwrite']))->toThrow(LogicException::class);
    $this->get('/knowledge/renewals/'.$renewal->id)->assertInertia(fn (Assert $p) => $p->has('renewals', 2));
});
it('validates renewal chronology and requires the dated workflow for completion', function () {
    $u = knowledgeStaff();
    $record = knowledgeRenewal($u);
    $this->actingAs($u)->patchJson('/knowledge/renewals/'.$record->id, ['version' => 1, 'status' => 'renewed'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson('/knowledge/renewals/'.$record->id.'/renew', ['version' => 1, 'renewed_on' => now()->addDays(2)->toDateString()])->assertUnprocessable();
    $this->postJson('/knowledge/renewals/'.$record->id.'/renew', ['version' => 1, 'renewed_on' => now()->toDateString(), 'next_expiry_on' => now()->toDateString()])->assertUnprocessable();
    $this->postJson('/knowledge/renewals/'.$record->id.'/renew', ['version' => 1, 'renewed_on' => now()->toDateString(), 'next_remind_on' => now()->addDay()->toDateString()])->assertUnprocessable();
    expect($record->fresh()->version)->toBe(1)->and(RenewalEvent::count())->toBe(0);
});
it('revalidates renewal reminder owner version permission completion and disabled module state', function () {
    $u = knowledgeStaff();
    $record = knowledgeRenewal($u);
    $reminders = app(WorkReminders::class);
    $reminders->enqueueDue();
    $notice = WorkReminder::where('module', 'knowledge')->sole();
    expect($reminders->preview($notice)['title'])->toBe('Renewal reminder: Professional insurance');
    app(Records::class)->save($u, 'renewals', ['version' => 1, 'name' => 'Updated insurance'], $record->id);
    expect($reminders->preview($notice))->toBeNull();
    $reminders->enqueueDue();
    $fresh = WorkReminder::where('module', 'knowledge')->latest('id')->first();
    $u->revokePermissionTo('knowledge.renewals.view.own');
    expect($reminders->preview($fresh))->toBeNull();
    $u->givePermissionTo('knowledge.renewals.view.own');
    knowledgeModules($this, []);
    expect($reminders->preview($fresh))->toBeNull();
    knowledgeModules($this, ['knowledge']);
    app(Records::class)->renew($u, $record->id, ['version' => 2, 'renewed_on' => now(Installation::settings()['timezone'])->toDateString()]);
    expect($reminders->preview($fresh))->toBeNull();
});
it('reports actual missing metadata rather than inventing a document age interval', function () {
    $u = knowledgeStaff(true, true);
    knowledgeEntry($u, ['title' => 'Old but current instructions', 'revision' => '2.0', 'revised_on' => '2001-01-01', 'updated_on' => '2001-01-01', 'document_name' => 'Current instructions', 'document_url' => 'https://example.test/old-current.pdf']);
    knowledgeEntry($u, ['title' => 'Draft needs review', 'status' => 'draft', 'revision' => null, 'revised_on' => null, 'updated_on' => null, 'owner_id' => null]);
    knowledgeRenewal($u);
    knowledgeRenewal($u, ['name' => 'Expired policy', 'expires_on' => now(Installation::settings()['timezone'])->subDay()->toDateString(), 'remind_on' => null]);
    $this->actingAs($u)->get('/knowledge?section=library')->assertInertia(fn (Assert $p) => $p->where('metrics.current', 1)->where('metrics.missing-owner-library', 1)->where('metrics.missing-document', 1)->where('metrics.upcoming', 1)->where('metrics.overdue', 1));
    $this->get('/knowledge?section=library&view=missing-document')->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1)->where('table.page.data.0.title', 'Draft needs review'));
    $u->revokePermissionTo('knowledge.documents.view');
    $this->get('/knowledge?section=library')->assertInertia(fn (Assert $p) => $p->missing('metrics.missing-document'));
});
it('keeps advanced search filtered export and formula escaping within the same owner and period scopes', function () {
    $u = knowledgeStaff();
    $other = knowledgeStaff();
    knowledgeEntry($u, ['title' => '=HYPERLINK(A1)', 'relevant_role' => 'Coordinator', 'updated_on' => '2026-10-25']);
    knowledgeEntry($u, ['title' => 'Other role', 'relevant_role' => 'Manager', 'updated_on' => '2026-10-25']);
    knowledgeEntry($other, ['title' => 'Hidden instructions', 'relevant_role' => 'Coordinator', 'updated_on' => '2026-10-25']);
    $query = ['section' => 'library', 'role' => 'Coordinator', 'owner_id' => $u->id, 'from' => '2026-10-25', 'to' => '2026-10-25', 'filters' => [['id' => 'category', 'op' => 'eq', 'value' => 'Delivery']]];
    $this->actingAs($u)->get('/knowledge?'.http_build_query($query))->assertInertia(fn (Assert $p) => $p->where('table.page.total', 1));
    $csv = $this->get('/knowledge/export?'.http_build_query($query))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK(A1)")->not->toContain('Hidden instructions', 'Other role', 'document_url');
    $u->revokePermissionTo('knowledge.library.export');
    $this->get('/knowledge/export?section=library')->assertForbidden();
});
it('links current instructions to scoped existing routines and redacts inaccessible relationships without clearing them', function () {
    knowledgeModules($this, ['knowledge', 'executive']);
    $u = knowledgeStaff();
    $routine = knowledgeRoutine($u);
    $entry = knowledgeEntry($u);
    app(Instructions::class)->add($u, $entry->id, ['version' => 1, 'reference_type' => 'routine', 'reference_id' => $routine->id, 'reference_version' => $routine->version]);
    $this->actingAs($u)->postJson('/knowledge/library/'.$entry->id.'/instructions', ['version' => 2, 'reference_type' => 'routine', 'reference_id' => $routine->id, 'reference_version' => $routine->version])->assertUnprocessable();
    expect(app(WorkReferences::class)->for($u, $routine))->toBe([['id' => 'knowledge.instructions.'.$entry->id, 'label' => $entry->title, 'href' => '/knowledge/library/'.$entry->id]]);
    $this->actingAs($u)->get('/executive/routines/'.$routine->id)->assertInertia(fn (Assert $p) => $p->where('instructionLinks.0.label', $entry->title));
    $u->revokePermissionTo('executive.view.own');
    $response = $this->get('/knowledge/library/'.$entry->id)->assertOk();
    expect($response->inertiaProps('instructions'))->toBe([])->and($response->inertiaProps('instructionOptions'))->toBe([])->and($response->getContent())->not->toContain('Review delivery instructions');
    $this->patch('/knowledge/library/'.$entry->id, ['version' => 2, 'title' => 'Retained link'])->assertRedirect();
    expect(DB::table('knowledge_instructions')->where('entry_id', $entry->id)->count())->toBe(1)->and(app(WorkReferences::class)->for($u, $routine))->toBe([]);
    $u->givePermissionTo('executive.view.own');
    expect(app(WorkReferences::class)->for($u, $routine)[0]['label'])->toBe('Retained link');
    $this->patch('/knowledge/library/'.$entry->id, ['version' => 3, 'status' => 'draft'])->assertRedirect();
    expect(app(WorkReferences::class)->for($u, $routine))->toBe([]);
    knowledgeModules($this, ['knowledge']);
    expect(app(Instructions::class)->forEntry($u, $entry->id))->toBe([]);
});
it('retains typed instruction link history while rejecting stale duplicate and inaccessible changes', function () {
    knowledgeModules($this, ['knowledge', 'executive']);
    $u = knowledgeStaff();
    $routine = knowledgeRoutine($u);
    $entry = knowledgeEntry($u);
    $this->actingAs($u)->postJson('/knowledge/library/'.$entry->id.'/instructions', ['version' => 1, 'reference_type' => 'routine', 'reference_id' => $routine->id, 'reference_version' => $routine->version + 1])->assertConflict();
    $other = knowledgeStaff();
    $foreign = knowledgeRoutine($other);
    $this->postJson('/knowledge/library/'.$entry->id.'/instructions', ['version' => 1, 'reference_type' => 'routine', 'reference_id' => $foreign->id, 'reference_version' => 1])->assertUnprocessable();
    $payload = ['reference_type' => 'routine', 'reference_id' => $routine->id, 'reference_version' => $routine->version];
    $this->postJson('/knowledge/library/'.$entry->id.'/instructions', ['version' => 1, ...$payload])->assertRedirect();
    $link = DB::table('knowledge_instructions')->sole();
    $this->postJson('/knowledge/library/'.$entry->id.'/instructions', ['version' => 2, ...$payload])->assertUnprocessable();
    $this->postJson('/knowledge/instructions/'.$link->id.'/archive', ['version' => 2, 'link_version' => 2])->assertConflict();
    expect(DB::table('knowledge_instructions')->find($link->id)->archived_at)->toBeNull();
    $this->postJson('/knowledge/instructions/'.$link->id.'/archive', ['version' => 2, 'link_version' => 1])->assertRedirect();
    expect(app(WorkReferences::class)->for($u, $routine))->toBe([]);
    $this->postJson('/knowledge/library/'.$entry->id.'/instructions', ['version' => 3, ...$payload])->assertRedirect();
    expect(DB::table('knowledge_instructions')->count())->toBe(1)
        ->and(DB::table('knowledge_instructions')->sole()->version)->toBe(3)
        ->and(app(WorkReferences::class)->for($u, $routine))->toHaveCount(1);

});
it('disables direct reads writes downloads exports reminders and widgets while retaining Knowledge data and grants', function () {
    $u = knowledgeStaff(false, true);
    $entry = knowledgeEntry($u);
    $doc = app(Records::class)->document($u, 'library', $entry->id, ['name' => 'Instructions', 'url' => 'https://example.test/instructions.pdf']);
    knowledgeRenewal($u);
    $count = KnowledgeHistory::count();
    knowledgeModules($this, []);
    $this->actingAs($u);
    foreach (['/knowledge', '/knowledge/library/'.$entry->id, '/knowledge/documents/'.$doc->id, '/knowledge/export?section=library'] as $url) {
        $this->get($url)->assertNotFound();
    }$this->patchJson('/knowledge/library/'.$entry->id, ['version' => 2, 'title' => 'Blocked'])->assertNotFound();
    expect((new KnowledgeModule)->widgets($u))->toBe([])->and(iterator_to_array((new DueReminders)->due()))->toBe([])->and(Entry::count())->toBe(1)->and(KnowledgeHistory::count())->toBe($count);
    knowledgeModules($this, ['knowledge']);
    $this->get('/knowledge/library/'.$entry->id)->assertOk();
    $new = User::factory()->create();
    $new->assignRole('Employee');
    $this->actingAs($new)->get('/knowledge')->assertForbidden();
    expect($new->can('knowledge.library.view.own'))->toBeFalse();
});

it('uses the library title for an optional unnamed initial document link', function () {
    $u = knowledgeStaff(false, true);
    $this->actingAs($u)->post('/knowledge/library', ['kind' => 'procedure', 'title' => 'Customer handover', 'status' => 'draft', 'owner_id' => $u->id, 'document_name' => '', 'document_url' => 'https://example.test/handover.pdf'])->assertRedirect();
    $entry = Entry::sole();
    $doc = KnowledgeDocument::sole();
    expect($doc->name)->toBe('Customer handover')->and($doc->entry_id)->toBe($entry->id)->and($entry->version)->toBe(2);
    $this->get('/knowledge/library/'.$entry->id)->assertInertia(fn (Assert $p) => $p->where('documents.0.name', 'Customer handover'));
});
