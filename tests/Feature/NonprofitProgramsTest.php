<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\WorkReminders;
use Awove\Nonprofit\NonprofitModule;
use Awove\Nonprofit\Programs\Capability;
use Awove\Nonprofit\Programs\FollowUpReminders;
use Awove\Nonprofit\Programs\Models\Attendance;
use Awove\Nonprofit\Programs\Models\History;
use Awove\Nonprofit\Programs\Models\Participant;
use Awove\Nonprofit\Programs\Presentation;
use Awove\Nonprofit\Programs\Records;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->withoutVite();
    $this->programsManifest = tempnam(sys_get_temp_dir(), 'crm-programs-');
    $this->programsSettings = Installation::manifest();
    $this->programsSettings['modules'] = ['nonprofit'];
    $this->programsSettings['capabilities'] = ['sales' => false, 'nonprofit:programs' => true, 'nonprofit:fundraising' => false, 'nonprofit:projects' => false];
    $this->programsSettings['timezone'] = 'Asia/Jerusalem';
    file_put_contents($this->programsManifest, json_encode($this->programsSettings));
    config(['core.manifest' => $this->programsManifest]);
    $this->artisan('crm:install')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00+02:00'));
});
afterEach(function () {
    unlink($this->programsManifest);
});
function npStaff(bool $all = false, bool $private = true): User
{
    $u = User::factory()->create(['name' => 'Shira Cohen']);
    $u->assignRole('Employee');
    $p = (new Capability)->permissions();
    if (! $all) {
        $p = array_values(array_diff($p, ['nonprofit.programs.view.all', 'nonprofit.programs.edit.all', 'nonprofit.programs.participants.view.all', 'nonprofit.programs.participants.edit.all']));
    }if (! $private) {
        $p = array_values(array_filter($p, fn ($v) => ! str_starts_with($v, 'nonprofit.programs.participants.') && $v !== 'nonprofit.programs.attendance.manage'));
    }$u->givePermissionTo($p);

    return $u;
}
function npCreate($u, string $kind = 'programs', array $data = [])
{
    $defaults = ['name' => match ($kind) {
        'participants' => 'Yael Shalev','locations' => 'Jerusalem center','groups' => 'Learning group',default => 'Community workshops'
    }, 'owner_id' => $u->id, 'active' => true, 'notes' => 'Fictional program information.'];
    if ($kind === 'participants') {
        $defaults = [...$defaults, 'participant_code' => 'P-101', 'email' => 'yael@example.test', 'phone' => '+1 202-555-0101'];
    }

    return app(Records::class)->save($u, $kind, [...$defaults, ...$data]);
}
function npSession($u, $program, array $data = [])
{
    return app(Records::class)->child($u, 'sessions', $program->id, ['program_version' => $program->fresh()->version, 'name' => 'Creative workshop', 'session_on' => '2026-10-30', ...$data]);
}
function npAttend($u, $program, $session, $participant, array $data = [])
{
    return app(Records::class)->attend($u, $session->id, ['program_version' => $program->fresh()->version, 'session_version' => $session->fresh()->version, 'participant_id' => $participant->id, 'status' => 'present', ...$data]);
}
function npFeedback($u, $program, array $data = [])
{
    return app(Records::class)->child($u, 'feedback', $program->id, ['program_version' => $program->fresh()->version, 'feedback_on' => '2026-10-30', 'comments' => 'The fictional workshop was useful.', 'follow_up_owner_id' => $u->id, 'next_action' => 'Call the group coordinator', 'due_on' => '2026-10-31', ...$data]);
}
function npSettings($test): void
{
    file_put_contents($test->programsManifest, json_encode($test->programsSettings));
}
it('persists Programs with locations groups participants and dated sessions independently of every other optional module', function () {
    $u = npStaff();
    $location = npCreate($u, 'locations');
    $group = npCreate($u, 'groups', ['location_id' => $location->id]);
    $program = npCreate($u, 'programs', ['location_id' => $location->id, 'group_id' => $group->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31']);
    $participant = npCreate($u, 'participants', ['program_id' => $program->id, 'group_id' => $group->id, 'location_id' => $location->id]);
    $session = npSession($u, $program);
    npAttend($u, $program, $session, $participant);
    $this->actingAs($u)->get('/nonprofit/programs/participants/'.$participant->id)->assertInertia(fn (Assert $p) => $p->where('record.participant_code', 'P-101')->where('record.name', 'Yael Shalev')->where('record.email', 'yael@example.test')->where('record.program.name', 'Community workshops'));
    $this->get('/nonprofit/programs/programs/'.$program->id)->assertInertia(fn (Assert $p) => $p->where('sessions.0.session_on', '2026-10-30')->where('record.starts_on', '2026-10-01')->where('record.location.name', 'Jerusalem center'));
    foreach (['sales', 'finance', 'operations', 'assets', 'hr', 'marketing', 'knowledge', 'customer-service'] as $m) {
        expect(app(Modules::class)->enabled($m))->toBeFalse();
    }
    expect(DB::table('nonprofit_attendance')->count())->toBe(1)->and(DB::table('nonprofit_program_history')->count())->toBeGreaterThan(5);
});
it('does not auto grant restricted roster or document permissions and supports view all without view own navigation', function () {
    $u = User::factory()->create();
    $u->assignRole('Employee');
    expect($u->can('nonprofit.programs.participants.view.all'))->toBeFalse();
    $u->givePermissionTo('nonprofit.programs.view.all');
    $owner = npStaff();
    $p = npCreate($owner);
    $this->actingAs($u)->get('/nonprofit/programs')->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1)->where('canViewParticipants', false)->where('participants', [])->where('contacts', [])->where('summaries', null));
    expect((new NonprofitModule)->sections($u))->toContain(['label' => 'Programs', 'href' => '/nonprofit/programs']);
    $this->get('/nonprofit/programs?section=participants')->assertForbidden();
    $this->get('/nonprofit/programs/export?section=participants')->assertForbidden();
});
it('keeps private participants out of broad program readers and jointly scopes attendance histories and table columns', function () {
    $owner = npStaff();
    $reader = npStaff(true, false);
    $p = npCreate($owner);
    $person = npCreate($owner, 'participants', ['notes' => 'Private roster note']);
    $s = npSession($owner, $p);
    $a = npAttend($owner, $p, $s, $person);
    $this->actingAs($reader)->get('/nonprofit/programs/programs/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('participants', [])->where('canManageAttendance', false)->where('history', fn ($rows) => collect($rows)->every(fn ($r) => ! str_starts_with($r['event'], 'attendance.'))));
    $this->get('/nonprofit/programs/participants/'.$person->id)->assertNotFound();
    $this->get('/nonprofit/programs/attendance/'.$a->id)->assertNotFound();
    $this->get('/nonprofit/programs?section=attendance')->assertForbidden();
    $this->patchJson('/nonprofit/programs/participants/'.$person->id, ['version' => 1, 'name' => 'Changed'])->assertNotFound();
});
it('redacts inaccessible Contact and program references while preserving them on an ordinary private roster save', function () {
    $admin = npStaff(true);
    $staff = npStaff();
    $contact = Contact::create(['name' => 'Noam Levi', 'email' => 'private-contact@example.test', 'phone' => '+1 202-555-0102', 'search_key' => 'noamlevi', 'created_by' => $admin->id]);
    $program = npCreate($admin);
    $person = npCreate($admin, 'participants', ['contact_id' => $contact->id, 'program_id' => $program->id, 'owner_id' => $staff->id]);
    $this->actingAs($staff)->get('/nonprofit/programs/participants/'.$person->id)->assertInertia(fn (Assert $page) => $page->where('record.contact_id', null)->where('record.contact', null)->where('record.contact_restricted', true)->where('record.program_id', null)->where('record.program_restricted', true));
    $this->patchJson('/nonprofit/programs/participants/'.$person->id, ['version' => 1, 'notes' => 'Updated fictional note', 'contact_id' => null, 'program_id' => null])->assertRedirect();
    expect($person->fresh()->getAttribute('contact_id'))->toBe($contact->id)->and($person->fresh()->getAttribute('program_id'))->toBe($program->id);
    $other = Contact::create(['name' => 'Tal Cohen', 'search_key' => 'talcohen', 'created_by' => $staff->id]);
    $this->patchJson('/nonprofit/programs/participants/'.$person->id, ['version' => 2, 'contact_id' => $other->id])->assertForbidden();
});
it('validates unique participant codes dates selected references and assignment while preserving null ownership on edit', function () {
    $u = npStaff();
    $p = npCreate($u, 'participants');
    $this->actingAs($u)->postJson('/nonprofit/programs/participants', ['name' => 'Maya Erez', 'participant_code' => 'p-101', 'active' => true])->assertUnprocessable()->assertJsonValidationErrors('participant_code');
    $this->postJson('/nonprofit/programs/programs', ['name' => 'Workshops', 'active' => true, 'starts_on' => '2026-12-31', 'ends_on' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    $this->postJson('/nonprofit/programs/programs', ['name' => 'Workshops', 'active' => true, 'ends_on' => '2026-12-31'])->assertRedirect();
    $free = npCreate($u, 'programs', ['owner_id' => null]);
    $u->givePermissionTo(['nonprofit.programs.view.all', 'nonprofit.programs.edit.all']);
    $u->revokePermissionTo('nonprofit.programs.assign');
    $this->patchJson('/nonprofit/programs/programs/'.$free->id, ['version' => 1, 'notes' => 'Still unassigned'])->assertRedirect();
    expect($free->fresh()->owner_id)->toBeNull();
    $owned = npCreate($u);
    $this->patchJson('/nonprofit/programs/programs/'.$owned->id, ['version' => 1, 'owner_id' => null])->assertForbidden();
});
it('enforces one attendance per participant session and rejects stale concurrent submissions without silent overwrites', function () {
    $u = npStaff();
    $p = npCreate($u);
    $s = npSession($u, $p);
    $person = npCreate($u, 'participants');
    $payload = ['program_version' => $p->fresh()->version, 'session_version' => $s->fresh()->version, 'participant_id' => $person->id, 'status' => 'present'];
    $this->actingAs($u)->postJson('/nonprofit/programs/sessions/'.$s->id.'/attendance', $payload)->assertRedirect();
    $this->postJson('/nonprofit/programs/sessions/'.$s->id.'/attendance', $payload)->assertConflict();
    $fresh = [...$payload, 'program_version' => $p->fresh()->version, 'session_version' => $s->fresh()->version];
    $this->postJson('/nonprofit/programs/sessions/'.$s->id.'/attendance', $fresh)->assertUnprocessable()->assertJsonValidationErrors('participant_id');
    expect(fn () => DB::transaction(fn () => DB::table('nonprofit_attendance')->insert(['session_id' => $s->id, 'participant_id' => $person->id, 'status' => 'absent', 'created_by' => $u->id])))->toThrow(QueryException::class);
    expect(Attendance::count())->toBe(1);
    $a = Attendance::sole();
    $this->patchJson('/nonprofit/programs/attendance/'.$a->id, [...$fresh, 'version' => 1, 'status' => 'excused'])->assertRedirect();
    expect($a->fresh()->getAttribute('status'))->toBe('excused')->and(History::where('kind', 'attendance')->count())->toBe(2);
});
it('retains historical attendance after participant reassignment deactivation and archive without inventing missing absences', function () {
    $u = npStaff();
    $p = npCreate($u);
    $s = npSession($u, $p);
    $person = npCreate($u, 'participants', ['program_id' => $p->id]);
    $a = npAttend($u, $p, $s, $person);
    $other = npCreate($u, 'programs', ['name' => 'Skills workshops']);
    app(Records::class)->save($u, 'participants', ['version' => 1, 'program_id' => $other->id, 'active' => false], $person->id);
    app(Records::class)->archive($u, 'participants', $person->id, ['version' => 2]);
    $this->actingAs($u)->get('/nonprofit/programs?section=attendance&from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1)->where('summaries.0.present', 1)->where('summaries.0.absent', 0)->where('summaries.0.excused', 0));
    expect($a->fresh()->getAttribute('session_id'))->toBe($s->id);
});
it('summarizes recorded statuses by actual session date program location and current advanced filters', function () {
    $u = npStaff();
    $location = npCreate($u, 'locations');
    $p = npCreate($u, 'programs', ['location_id' => $location->id]);
    $person = npCreate($u, 'participants');
    $s = npSession($u, $p, ['location_id' => $location->id]);
    npAttend($u, $p, $s, $person);
    $older = npSession($u, $p, ['session_on' => '2026-09-10', 'location_id' => $location->id]);
    npAttend($u, $p, $older, $person, ['status' => 'absent']);
    $this->actingAs($u)->get('/nonprofit/programs?'.http_build_query(['section' => 'attendance', 'from' => '2026-10-01', 'to' => '2026-10-31', 'program_id' => $p->id, 'location_id' => $location->id, 'filters' => [['id' => 'status', 'op' => 'eq', 'value' => 'present']]]))->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1)->where('summaries.0.present', 1)->where('summaries.0.absent', 0)->where('summaries.0.location', 'Jerusalem center'));
});
it('never allows a guessed or newly inaccessible participant into an attendance mutation or roster export', function () {
    $u = npStaff();
    $other = npStaff();
    $p = npCreate($u);
    $s = npSession($u, $p);
    $person = npCreate($other, 'participants');
    $this->actingAs($u)->postJson('/nonprofit/programs/sessions/'.$s->id.'/attendance', ['program_version' => $p->fresh()->version, 'session_version' => $s->version, 'participant_id' => $person->id, 'status' => 'present'])->assertNotFound();
    $this->get('/nonprofit/programs/export?section=participants')->assertOk();
    expect(Attendance::count())->toBe(0);
});
it('shows safe Contact details only with independent access and never changes the shared identity', function () {
    $u = npStaff();
    $c = Contact::create(['name' => 'Noam Levi', 'email' => 'noam@example.test', 'phone' => '+1 202-555-0103', 'search_key' => 'noamlevi', 'created_by' => $u->id]);
    $r = npCreate($u, 'participants', ['contact_id' => $c->id]);
    $this->actingAs($u)->get('/nonprofit/programs/participants/'.$r->id)->assertInertia(fn (Assert $page) => $page->where('record.contact.email', 'noam@example.test')->where('record.contact.phone', '+1 202-555-0103'));
    $this->patch('/nonprofit/programs/participants/'.$r->id, ['version' => 1, 'email' => 'private-updated@example.test'])->assertRedirect();
    expect($c->fresh()->email)->toBe('noam@example.test');
});
it('protects program document links through fresh parent authorization and separate permissions', function () {
    $u = npStaff();
    $p = npCreate($u);
    $d = app(Records::class)->document($u, $p->id, ['program_version' => 1, 'name' => 'Workshop resource', 'url' => 'https://example.test/program.pdf']);
    $this->actingAs($u)->get('/nonprofit/programs/documents/'.$d->id)->assertRedirect('https://example.test/program.pdf')->assertHeader('Cache-Control', 'no-store, private');
    $u->revokePermissionTo('nonprofit.programs.documents.view');
    $this->get('/nonprofit/programs/documents/'.$d->id)->assertForbidden();
    $this->get('/nonprofit/programs/programs/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('documents', []));
    $u->givePermissionTo('nonprofit.programs.documents.view');
    $this->postJson('/nonprofit/programs/programs/'.$p->id.'/documents', ['program_version' => $p->fresh()->version, 'name' => 'Unsafe', 'url' => 'http://example.test/file'])->assertUnprocessable();
    $other = npStaff();
    $this->actingAs($other)->get('/nonprofit/programs/documents/'.$d->id)->assertNotFound();
    app(Records::class)->archiveDocument($u, $d->id, ['version' => 1, 'program_version' => $p->fresh()->version]);
    $this->actingAs($u)->get('/nonprofit/programs/documents/'.$d->id)->assertNotFound();
    expect(DB::table('nonprofit_program_documents')->count())->toBe(1);
});
it('delivers ID-only scoped followup notices and suppresses rescheduled completed archived and disabled previews', function () {
    $u = npStaff();
    $p = npCreate($u);
    $f = npFeedback($u, $p);
    $source = new FollowUpReminders;
    expect($source->current($f->id, $u->id))->not->toBeNull();
    app(WorkReminders::class)->enqueueDue();
    $notice = WorkReminder::where('module', 'nonprofit')->sole();
    expect($notice->getAttributes())->not->toHaveKeys(['title', 'comments']);
    app(Records::class)->child($u, 'feedback', $p->id, ['program_version' => $p->fresh()->version, 'version' => 1, 'due_on' => '2026-11-10'], $f->id);
    expect($source->current($f->id, $u->id))->toBeNull();
    app(Records::class)->child($u, 'feedback', $p->id, ['program_version' => $p->fresh()->version, 'version' => 2, 'due_on' => '2026-10-31', 'completed_on' => '2026-10-31'], $f->id);
    expect($source->current($f->id, $u->id))->toBeNull();
    $this->programsSettings['capabilities']['nonprofit:programs'] = false;
    npSettings($this);
    expect($source->current($f->id, $u->id))->toBeNull();
    expect($source->due()->valid())->toBeFalse();
});
it('denies every direct surface while disabled retains rows layouts and restores them on reenable', function () {
    $u = npStaff();
    $p = npCreate($u);
    $person = npCreate($u, 'participants');
    $s = npSession($u, $p);
    $a = npAttend($u, $p, $s, $person);
    $f = npFeedback($u, $p);
    $d = app(Records::class)->document($u, $p->id, ['program_version' => $p->fresh()->version, 'name' => 'Resource', 'url' => 'https://example.test/file']);
    $u->forceFill(['display_preferences' => ['tables' => ['nonprofit.programs-participants' => ['columns' => ['participant_code', 'name']]]]])->save();
    $this->programsSettings['capabilities']['nonprofit:programs'] = false;
    npSettings($this);
    $this->actingAs($u);
    foreach (['/nonprofit/programs', '/nonprofit/programs/participants/'.$person->id, '/nonprofit/programs/sessions/'.$s->id, '/nonprofit/programs/attendance/'.$a->id, '/nonprofit/programs/export?section=participants', '/nonprofit/programs/documents/'.$d->id] as $url) {
        $this->get($url)->assertNotFound();
    }$this->patchJson('/nonprofit/programs/programs/'.$p->id, ['version' => $p->fresh()->version, 'name' => 'No'])->assertNotFound();
    expect((new Capability)->widgets($u))->toBe([]);
    expect((new Capability)->tableColumns('nonprofit.programs-participants', $u))->toBe([]);
    expect(Participant::count())->toBe(1)->and(Attendance::count())->toBe(1);
    $this->programsSettings['capabilities']['nonprofit:programs'] = true;
    npSettings($this);
    $this->get('/nonprofit/programs?section=participants')->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1)->where('table.preferences.columns', ['participant_code', 'name']));
});
it('exports the same authorized filtered rows using shared CSV formula safety without document or hidden relation data', function () {
    $u = npStaff();
    npCreate($u, 'participants', ['name' => '=SUM(1,2)', 'email' => 'formula@example.test']);
    npCreate($u, 'participants', ['participant_code' => 'P-102', 'name' => 'Maya Erez']);
    $response = $this->actingAs($u)->get('/nonprofit/programs/export?'.http_build_query(['section' => 'participants', 'q' => 'P-101']));
    $response->assertOk();
    $text = $response->streamedContent();
    expect($text)->toContain("'=SUM(1,2)")->toContain('P-101')->not->toContain('P-102')->not->toContain('https://');
});
it('refreshes the active actor and revocations at mutation and notification boundaries', function () {
    $u = npStaff();
    $p = npCreate($u);
    $f = npFeedback($u, $p);
    $u->forceFill(['active' => false])->save();
    expect(fn () => app(Records::class)->save($u, 'programs', ['version' => $p->fresh()->version, 'name' => 'Changed'], $p->id))->toThrow(HttpException::class);
    expect((new FollowUpReminders)->current($f->id, $u->id))->toBeNull();
    expect($p->fresh()->getAttribute('name'))->toBe('Community workshops');
});

it('reauthorizes public participant DTO and history projections independently of controller scope', function () {
    $owner = npStaff();
    $other = npStaff();
    $person = npCreate($owner, 'participants');
    expect(fn () => app(Presentation::class)->describe($other, 'participants', $person))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(Presentation::class)->history($other, 'participants', $person))->toThrow(ModelNotFoundException::class);
    $owner->forceFill(['active' => false])->save();
    expect(fn () => app(Presentation::class)->describe($owner, 'participants', $person))->toThrow(HttpException::class);
    expect(fn () => app(Presentation::class)->history($owner, 'participants', $person))->toThrow(HttpException::class);
});

it('uses useful fresh default columns and clickable primary fields for session attendance and feedback tables', function () {
    $u = npStaff();
    $this->actingAs($u);
    foreach (['sessions' => ['name', 'program', 'session_on', 'location', 'group'], 'attendance' => ['participant', 'session', 'session_on', 'status'], 'feedback' => ['feedback_on', 'program', 'next_action', 'follow_up_owner', 'due_on', 'completed_on']] as $section => $columns) {
        $this->get('/nonprofit/programs?section='.$section)->assertInertia(fn (Assert $p) => $p->where('table.defaultColumns', $columns)->where('table.preferences.columns', $columns));
    }
    $program = npCreate($u);
    $feedback = npFeedback($u, $program);
    expect(app(Presentation::class)->describe($u, 'feedback', $feedback)['record_link_column'])->toBe('feedback_on');
});

it('keeps sessions attendance feedback and matching CSV discoverable in archived views after parent archive', function () {
    $u = npStaff();
    $p = npCreate($u);
    $session = npSession($u, $p);
    $person = npCreate($u, 'participants');
    npAttend($u, $p, $session, $person);
    npFeedback($u, $p);
    app(Records::class)->archive($u, 'programs', $p->id, ['version' => $p->fresh()->version]);
    $this->actingAs($u);
    foreach (['sessions', 'attendance', 'feedback'] as $section) {
        $this->get('/nonprofit/programs?section='.$section)->assertInertia(fn (Assert $page) => $page->where('table.page.total', 0));
        $this->get('/nonprofit/programs?section='.$section.'&view=archived')->assertInertia(fn (Assert $page) => $page->where('table.page.total', 1));
    }
    $this->get('/nonprofit/programs/sessions/'.$session->id)->assertInertia(fn (Assert $page) => $page->where('record.editable', false));
    expect($this->get('/nonprofit/programs/export?section=attendance&view=archived')->streamedContent())->toContain('Yael Shalev');
});
