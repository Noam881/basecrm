<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Contact;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\WorkReminders;
use Awove\CustomerService\CustomerServiceModule;
use Awove\CustomerService\Models\Feedback;
use Awove\CustomerService\Models\History;
use Awove\CustomerService\Models\ServiceRequest;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->serviceManifest = tempnam(sys_get_temp_dir(), 'crm-service-test-');
    $manifest = Installation::manifest();
    $manifest['modules'] = ['customer-service'];
    $manifest['timezone'] = 'Asia/Jerusalem';
    file_put_contents($this->serviceManifest, json_encode($manifest));
    config(['core.manifest' => $this->serviceManifest]);
    $this->artisan('crm:install')->assertSuccessful();
});

afterEach(function () {
    if (is_file($this->serviceManifest)) {
        unlink($this->serviceManifest);
    }
});

function serviceUser(bool $all = false): User
{
    $user = User::factory()->create(['name' => 'Shira Levi', 'active' => true]);
    $user->assignRole('Employee');
    $user->givePermissionTo($all ? (new CustomerServiceModule)->permissions() : ['customer-service.view.own', 'customer-service.create', 'customer-service.edit.own', 'customer-service.export']);

    return $user;
}

function serviceCustomer(User $owner, string $name = 'Yael Shalev'): Contact
{
    return Contact::create(['name' => $name, 'email' => 'yael-'.$owner->id.'@example.test', 'created_by' => $owner->id, 'search_key' => 'yaelshalev']);
}

function serviceRequestData(User $owner, array $extra = []): array
{
    return [...['subject' => 'Update customer details', 'description' => 'The customer asked us to correct the delivery details.', 'contact_id' => serviceCustomer($owner)->id, 'company_id' => null, 'owner_id' => $owner->id, 'received_on' => '2026-10-24', 'channel' => 'email', 'status' => 'new', 'priority' => 'normal', 'next_action' => 'Call to confirm details', 'due_on' => '2026-10-24'], ...$extra];
}

function serviceRequestFor(User $owner, array $extra = []): ServiceRequest
{
    return ServiceRequest::create([...serviceRequestData($owner, $extra), 'created_by' => $owner->id]);
}

function serviceFeedbackData(ServiceRequest $request, array $extra = []): array
{
    return [...['contact_id' => $request->contact_id, 'company_id' => $request->company_id, 'request_id' => $request->id, 'owner_id' => $request->owner_id, 'received_on' => '2026-10-24', 'rating' => 2, 'comments' => 'Please call to discuss the response.', 'action_required' => true, 'follow_up_on' => '2026-10-24', 'followed_up_on' => null], ...$extra];
}

function serviceDisable(): void
{
    $manifest = Installation::manifest();
    $manifest['modules'] = [];
    file_put_contents(config('core.manifest'), json_encode($manifest));
}

it('runs Service without Operations and persists a complete manually recorded service workflow', function () {
    $user = serviceUser();
    expect(app(Modules::class)->enabled('customer-service'))->toBeTrue()->and(app(Modules::class)->enabled('operations'))->toBeFalse();
    $data = serviceRequestData($user, ['document_url' => 'https://documents.example.test/customer-resolution']);
    $this->actingAs($user)->post('/service/requests', $data)->assertRedirect();
    $record = ServiceRequest::sole();
    expect($record->owner_id)->toBe($user->id)->and($record->received_on->toDateString())->toBe('2026-10-24');
    $this->post('/service/requests/'.$record->id.'/interactions', ['kind' => 'call', 'occurred_at' => '2026-10-24T12:30:00+03:00', 'summary' => 'Confirmed the correct address.', 'actor_id' => 999])->assertRedirect();
    $interaction = History::where('event', 'interaction')->sole();
    expect($interaction->actor_id)->toBe($user->id)->and($interaction->kind)->toBe('call')->and($interaction->occurred_at->utc()->format('H:i'))->toBe('09:30');
    $this->patch('/service/requests/'.$record->id, [...$data, 'status' => 'resolved', 'resolution' => 'Updated the address after confirmation.', 'resolved_on' => '2026-10-24', 'version' => 1])->assertRedirect();
    $this->patch('/service/requests/'.$record->id, [...$data, 'status' => 'closed', 'resolution' => 'Customer confirmed resolution.', 'version' => 2])->assertRedirect();
    $this->patch('/service/requests/'.$record->id, [...$data, 'status' => 'in-progress', 'resolution' => '', 'resolved_on' => null, 'version' => 3])->assertRedirect();
    expect($record->fresh()->status)->toBe('in-progress')->and($record->fresh()->resolution)->toBe('Customer confirmed resolution.')
        ->and($record->fresh()->resolved_on->toDateString())->toBe('2026-10-24')->and(History::where('event', 'reopened')->count())->toBe(1);
    expect(History::where('event', 'status_changed')->pluck('details')->pluck('resolution')->all())->toBe(['Updated the address after confirmation.', 'Customer confirmed resolution.']);
    $this->get('/service/requests/'.$record->id)->assertInertia(fn (Assert $page) => $page->component('CustomerService/Request')->has('history', 5)->where('record.editable', true)->where('record.customer.name', 'Yael Shalev'));
    $this->get('/service/requests/'.$record->id.'/document')->assertRedirect('https://documents.example.test/customer-resolution');
    expect(fn () => $interaction->update(['summary' => 'Overwritten']))->toThrow(LogicException::class);
});

it('keeps staff permissions explicit and denies unauthorized module entrypoints', function () {
    $user = User::factory()->create(['active' => true]);
    $user->assignRole('Employee');
    expect($user->can('customer-service.view.own'))->toBeFalse();
    $this->actingAs($user)->get('/service')->assertForbidden();
    $this->post('/service/requests', [])->assertForbidden();
    $this->get('/service/export')->assertForbidden();
    $this->post('/service/feedback', [])->assertForbidden();
});

it('enforces own versus all read and edit independently and never exposes inaccessible customer selectors', function () {
    $owner = serviceUser();
    $other = serviceUser();
    $request = serviceRequestFor($owner, ['document_url' => 'https://documents.example.test/private']);
    $feedback = Feedback::create([...serviceFeedbackData($request), 'created_by' => $owner->id]);
    $base = '/service/requests/'.$request->id;
    $this->actingAs($other)->get($base)->assertForbidden();
    $this->get($base.'/document')->assertForbidden();
    $this->patch($base, ['version' => 1])->assertForbidden();
    $this->post($base.'/interactions', ['kind' => 'note', 'occurred_at' => '2026-10-24T12:00:00+03:00', 'summary' => 'Forbidden'])->assertForbidden();
    $this->get('/service/feedback/'.$feedback->id)->assertForbidden();
    $this->get('/service?search=Update')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 0)->has('feedback.data', 0)->has('contacts', 0));
    $this->get('/service/export')->assertOk()->assertStreamedContent("ID,Subject,Customer,Status,Priority,Owner,Received,\"Next action\",Due,\"Resolution date\"\n");
    $data = serviceRequestData($other, ['contact_id' => $request->contact_id]);
    $this->post('/service/requests', $data)->assertForbidden();
    $other->givePermissionTo('customer-service.view.all');
    $this->get($base)->assertInertia(fn (Assert $page) => $page->where('record.editable', false)->where('record.customer', null)->where('record.contact_id', null));
    $this->patch($base, [...$data, 'version' => 1])->assertForbidden();
    $this->get('/service?contact_id='.$request->contact_id)->assertForbidden();
    $csv = $this->get('/service/export')->assertOk()->streamedContent();
    expect($csv)->toContain('Update customer details')->not->toContain('Yael Shalev');
});

it('supports unassigned queues only for all readers and prevents arbitrary owner assignment', function () {
    $owner = serviceUser();
    $other = serviceUser();
    $admin = serviceUser(true);
    $data = serviceRequestData($owner);
    $this->actingAs($owner)->post('/service/requests', [...$data, 'owner_id' => $other->id])->assertForbidden();
    $this->get('/service?view=unassigned')->assertForbidden();
    $admin->givePermissionTo('identities.manage');
    $this->actingAs($admin)->post('/service/requests', [...$data, 'owner_id' => null])->assertRedirect();
    $record = ServiceRequest::sole();
    $this->get('/service?view=unassigned')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('metrics.unassigned', 1));
    $this->patch('/service/requests/'.$record->id, [...$data, 'owner_id' => $other->id, 'version' => 1])->assertRedirect();
    $this->actingAs($other)->get('/service/requests/'.$record->id)->assertInertia(fn (Assert $page) => $page->where('record.customer', null)->where('record.contact_id', null));
    // Assignment does not grant shared directory access, nor prevent work on the request.
    $this->patch('/service/requests/'.$record->id, [...$data, 'contact_id' => null, 'owner_id' => $other->id, 'subject' => 'Confirm updated details', 'version' => 2])->assertRedirect();
    expect($record->fresh()->contact_id)->toBe($data['contact_id']);
});

it('retains disabled service data while denying every direct entrypoint and suppressing queued jobs and previews', function () {
    $user = serviceUser();
    $record = serviceRequestFor($user, ['document_url' => 'https://documents.example.test/private']);
    $feedback = Feedback::create([...serviceFeedbackData($record), 'created_by' => $user->id]);
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    app(WorkReminders::class)->enqueueDue();
    expect(WorkReminder::count())->toBe(2);
    $this->actingAs($user)->get('/reminders')->assertInertia(fn (Assert $page) => $page->has('workReminders', 2));
    $job = new DeliverWorkReminder('customer-service', 'requests', $record->id, 1, $user->id);
    serviceDisable();
    $base = '/service/requests/'.$record->id;
    $this->get('/service')->assertForbidden();
    $this->get($base)->assertForbidden();
    $this->get($base.'/document')->assertForbidden();
    $this->get('/service/export')->assertForbidden();
    $this->post('/service/requests', [])->assertForbidden();
    $this->patch($base, [])->assertForbidden();
    $this->post($base.'/archive', [])->assertForbidden();
    $this->post($base.'/interactions', [])->assertForbidden();
    $this->get('/service/feedback/'.$feedback->id)->assertForbidden();
    $this->patch('/service/feedback/'.$feedback->id, [])->assertForbidden();
    $this->post('/service/feedback/'.$feedback->id.'/archive', [])->assertForbidden();
    $this->post('/service/feedback', [])->assertForbidden();
    $this->get('/reminders')->assertInertia(fn (Assert $page) => $page->has('workReminders', 0));
    $this->post('/work-reminders/'.WorkReminder::first()->id.'/read')->assertNotFound();
    WorkReminder::query()->delete();
    $job->handle();
    app(WorkReminders::class)->enqueueDue();
    expect(WorkReminder::count())->toBe(0)->and(ServiceRequest::count())->toBe(1)->and(Feedback::count())->toBe(1);
    $manifest = Installation::manifest();
    $manifest['modules'] = ['customer-service'];
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $this->get($base)->assertOk();
});

it('deduplicates generic reminders and rejects obsolete rescheduled resolved archived or access-revoked work', function () {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    $user = serviceUser();
    $record = serviceRequestFor($user);
    $job = new DeliverWorkReminder('customer-service', 'requests', $record->id, 1, $user->id);
    $job->handle();
    $job->handle();
    expect(WorkReminder::count())->toBe(1);
    $this->actingAs($user)->post('/work-reminders/'.WorkReminder::sole()->id.'/read')->assertRedirect();
    expect($record->fresh()->status)->toBe('new');
    $record->update(['due_on' => '2026-10-26', 'version' => 2]);
    $job->handle();
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    $record->update(['due_on' => '2026-10-24']);
    (new DeliverWorkReminder('customer-service', 'requests', $record->id, 2, $user->id))->handle();
    expect(WorkReminder::count())->toBe(2);
    $record->update(['status' => 'resolved']);
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    $record->update(['status' => 'new', 'archived_at' => now()]);
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    $record->update(['archived_at' => null]);
    $user->revokePermissionTo('customer-service.view.own');
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    (new DeliverWorkReminder('customer-service', 'requests', $record->id, 2, $user->id))->handle();
    expect(WorkReminder::count())->toBe(2);
    $user->givePermissionTo('customer-service.view.own');
    $user->forceFill(['active' => false])->save();
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
});

it('uses installation calendar dates for due reminders and never creates automatic follow-up occurrences', function () {
    $user = serviceUser();
    serviceRequestFor($user, ['due_on' => '2026-10-25']);
    $this->travelTo(CarbonImmutable::parse('2026-10-24T20:59:00+00:00'));
    app(WorkReminders::class)->enqueueDue();
    expect(WorkReminder::count())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-10-24T21:01:00+00:00'));
    app(WorkReminders::class)->enqueueDue();
    app(WorkReminders::class)->enqueueDue();
    expect(WorkReminder::count())->toBe(1)->and(ServiceRequest::count())->toBe(1);
});

it('persists documented feedback ratings with optional request and explicit action completion', function () {
    $user = serviceUser();
    $record = serviceRequestFor($user);
    $data = serviceFeedbackData($record);
    $this->actingAs($user)->post('/service/feedback', [...$data, 'rating' => 6])->assertSessionHasErrors('rating');
    $this->post('/service/feedback', [...$data, 'follow_up_on' => null])->assertSessionHasErrors('follow_up_on');
    $this->post('/service/feedback', $data)->assertRedirect();
    $feedback = Feedback::sole();
    $this->get('/service/feedback/'.$feedback->id)->assertInertia(fn (Assert $page) => $page->where('record.rating', 2)->where('record.request_id', $record->id));
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    app(WorkReminders::class)->enqueueDue();
    $this->patch('/service/feedback/'.$feedback->id, [...$data, 'followed_up_on' => '2026-10-24', 'version' => 1])->assertRedirect();
    expect(collect(app(WorkReminders::class)->forUser($user))->pluck('title')->all())->toBe(['Service action: Update customer details']);
    $this->patch('/service/feedback/'.$feedback->id, [...$data, 'version' => 1])->assertConflict();
    $this->post('/service/feedback', [...$data, 'request_id' => null, 'action_required' => false, 'follow_up_on' => null])->assertRedirect();
    expect(Feedback::count())->toBe(2);
});

it('rejects feedback cross-customer binding and unauthorized request relations', function () {
    $user = serviceUser();
    $other = serviceUser();
    $request = serviceRequestFor($user);
    $hidden = serviceRequestFor($other);
    $data = serviceFeedbackData($request);
    $this->actingAs($user)->post('/service/feedback', [...$data, 'contact_id' => serviceCustomer($user, 'Noam Erez')->id])->assertSessionHasErrors('request_id');
    $this->post('/service/feedback', [...$data, 'request_id' => $hidden->id])->assertForbidden();
    expect(Feedback::count())->toBe(0);
});

it('provides scoped overdue waiting owner priority and resolution-date overviews and formula-safe CSV export', function () {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    $user = serviceUser();
    $other = serviceUser();
    $record = serviceRequestFor($user, ['subject' => '=HYPERLINK("https://example.test")', 'status' => 'waiting-customer', 'priority' => 'high', 'due_on' => '2026-10-23']);
    serviceRequestFor($user, ['subject' => 'Resolved request', 'status' => 'resolved', 'resolution' => 'Done', 'resolved_on' => '2026-10-22', 'received_on' => '2026-09-01']);
    serviceRequestFor($other, ['subject' => 'Hidden request', 'due_on' => '2026-10-23']);
    $this->actingAs($user)->get('/service?view=overdue&priority=high')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('metrics.overdue', 1)->where('metrics.awaiting', 1));
    $this->get('/service?date_field=resolved_on&from=2026-10-01&to=2026-10-24')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('metrics.resolved', 1));
    $export = $this->get('/service/export?view=overdue')->assertOk()->streamedContent();
    expect($export)->toContain("'=HYPERLINK")->not->toContain('Hidden request');
    $this->post('/service/requests/'.$record->id.'/archive', ['version' => 1])->assertRedirect('/service');
    $this->get('/service?view=archived')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('table.page.data.0.editable', false));
    $this->get('/service/requests/'.$record->id)->assertOk();
    $this->post('/service/requests/'.$record->id.'/interactions', ['kind' => 'note', 'occurred_at' => '2026-10-24T12:00:00+03:00', 'summary' => 'No'])->assertForbidden();
});

it('validates resolution and document links and rejects stale important updates', function () {
    $user = serviceUser();
    $data = serviceRequestData($user);
    $this->actingAs($user)->post('/service/requests', [...$data, 'status' => 'resolved'])->assertSessionHasErrors('resolution');
    foreach (['javascript:alert(1)', 'http://documents.example.test/file', 'https://name:secret@documents.example.test/file'] as $url) {
        $this->post('/service/requests', [...$data, 'document_url' => $url])->assertSessionHasErrors('document_url');
    }
    expect(ServiceRequest::count())->toBe(0);
    $this->post('/service/requests', $data)->assertRedirect();
    $record = ServiceRequest::sole();
    $this->patch('/service/requests/'.$record->id, [...$data, 'subject' => 'Changed subject', 'version' => 1])->assertRedirect();
    $this->patch('/service/requests/'.$record->id, [...$data, 'subject' => 'Stale subject', 'version' => 1])->assertConflict();
    expect($record->fresh()->subject)->toBe('Changed subject');
});

it('shows Service navigation to all-only readers and retains scoped feedback changes', function () {
    $user = serviceUser();
    $user->revokePermissionTo('customer-service.view.own');
    $user->givePermissionTo('customer-service.view.all');
    expect(collect(app(Modules::class)->navigation($user))->pluck('href')->all())->toContain('/service');
    $record = serviceRequestFor($user);
    $data = serviceFeedbackData($record);
    $this->actingAs($user)->post('/service/feedback', $data)->assertRedirect();
    $feedback = Feedback::sole();
    $this->patch('/service/feedback/'.$feedback->id, [...$data, 'rating' => 4, 'comments' => 'The response was helpful.', 'version' => 1])->assertRedirect();
    $this->get('/service/feedback/'.$feedback->id)->assertInertia(fn (Assert $page) => $page->has('history', 2)->where('history.0.details.before.rating', 2)->where('history.0.details.after.rating', 4));
    $this->post('/service/feedback/'.$feedback->id.'/archive', ['version' => 2])->assertRedirect();
    expect(History::where('feedback_id', $feedback->id)->pluck('event')->all())->toBe(['feedback.created', 'feedback.updated', 'feedback.archived']);
});

it('preserves unassigned owners and inaccessible historical request links on ordinary edits', function () {
    $admin = serviceUser(true);
    $owner = serviceUser();
    $request = serviceRequestFor($owner);
    $feedback = Feedback::create([...serviceFeedbackData($request), 'created_by' => $owner->id]);
    $request->update(['owner_id' => null]);
    $this->actingAs($owner)->patch('/service/feedback/'.$feedback->id, [...serviceFeedbackData($request), 'request_id' => null, 'owner_id' => $owner->id, 'comments' => 'An additional comment.', 'version' => 1])->assertRedirect();
    expect($feedback->fresh()->request_id)->toBe($request->id);
    $admin->revokePermissionTo('customer-service.assign');
    $data = $request->only(['subject', 'description', 'contact_id', 'company_id', 'received_on', 'channel', 'status', 'priority', 'next_action', 'due_on']);
    $data['received_on'] = $request->received_on->toDateString();
    $data['due_on'] = $request->due_on->toDateString();
    $this->actingAs($admin)->patch('/service/requests/'.$request->id, [...$data, 'subject' => 'Unassigned remains unassigned', 'version' => 1])->assertRedirect();
    expect($request->fresh()->owner_id)->toBeNull();
    $feedback->update(['owner_id' => null, 'action_required' => false]);
    $this->patch('/service/feedback/'.$feedback->id, [...serviceFeedbackData($request), 'owner_id' => null, 'action_required' => false, 'comments' => 'Unassigned feedback retained', 'version' => 2])->assertRedirect();
    expect($feedback->fresh()->owner_id)->toBeNull();
});

it('retains an initial closed resolution when reopened and subsequently resolved again', function () {
    $user = serviceUser();
    $data = serviceRequestData($user, ['status' => 'closed', 'resolution' => 'Original complete resolution.', 'resolved_on' => '2026-10-24', 'document_url' => 'https://documents.example.test/original']);
    $this->actingAs($user)->post('/service/requests', $data)->assertRedirect();
    $record = ServiceRequest::sole();
    $this->patch('/service/requests/'.$record->id, [...$data, 'status' => 'new', 'version' => 1])->assertRedirect();
    $this->patch('/service/requests/'.$record->id, [...$data, 'status' => 'resolved', 'resolution' => 'Second resolution.', 'document_url' => 'https://documents.example.test/updated', 'version' => 2])->assertRedirect();
    expect(History::where('event', 'created')->sole()->details)->toMatchArray(['resolution' => 'Original complete resolution.', 'resolved_on' => '2026-10-24', 'document_url' => 'https://documents.example.test/original']);
    expect($record->fresh()->resolution)->toBe('Second resolution.');
    $initial = History::where('event', 'created')->sole();
    $retainedUrl = '/service/requests/'.$record->id.'/history/'.$initial->id.'/document';
    $this->get($retainedUrl)->assertRedirect('https://documents.example.test/original');
    $other = serviceRequestFor($user);
    $this->get('/service/requests/'.$other->id.'/history/'.$initial->id.'/document')->assertNotFound();
    serviceDisable();
    $this->get($retainedUrl)->assertForbidden();
});

it('exports feedback using the same record scope and shared spreadsheet formula protection', function () {
    $owner = serviceUser();
    $other = serviceUser();
    $request = serviceRequestFor($owner);
    Feedback::create([...serviceFeedbackData($request, ['comments' => '=SUM(1,2)']), 'created_by' => $owner->id]);
    $hidden = serviceRequestFor($other);
    Feedback::create([...serviceFeedbackData($hidden, ['comments' => 'Hidden feedback']), 'created_by' => $other->id]);
    $csv = $this->actingAs($owner)->get('/service/export?type=feedback')->assertOk()->assertDownload('service-feedback.csv')->streamedContent();
    expect($csv)->toContain("'=SUM(1,2)")->not->toContain('Hidden feedback');
    $owner->revokePermissionTo('customer-service.export');
    $this->get('/service/export?type=feedback')->assertForbidden();
});

it('contributes only authorized live Service dashboard totals and accepts an end-date-only filter', function () {
    $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));
    $user = serviceUser();
    $other = serviceUser();
    serviceRequestFor($user, ['due_on' => '2026-10-23']);
    serviceRequestFor($other, ['due_on' => '2026-10-23']);
    $widgets = app(Modules::class)->dashboardWidgets($user);
    expect(array_column($widgets, 'value'))->toBe([1, 1]);
    $this->actingAs($user)->get('/service?to=2026-10-24')->assertOk();
    $user->revokePermissionTo('customer-service.view.own');
    expect((new CustomerServiceModule)->widgets($user))->toBe([]);
    $user->givePermissionTo('customer-service.view.all');
    expect(array_column((new CustomerServiceModule)->widgets($user), 'value'))->toBe([2, 2]);
    serviceDisable();
    expect((new CustomerServiceModule)->widgets($user))->toBe([]);
});

it('reuses the shared advanced table with scoped customer columns filters sorting layout and matching CSV', function () {
    $owner = serviceUser();
    $other = serviceUser();
    serviceRequestFor($owner, ['subject' => 'First request', 'priority' => 'low']);
    serviceRequestFor($owner, ['subject' => 'Second request', 'priority' => 'high']);
    serviceRequestFor($other, ['subject' => 'Hidden request', 'priority' => 'high']);
    $state = ['filters' => [['id' => 'priority', 'op' => 'eq', 'value' => 'high']], 'sorts' => [['id' => 'subject', 'direction' => 'desc']]];
    $this->actingAs($owner)->get('/service?'.http_build_query($state))->assertInertia(fn (Assert $page) => $page->where('table.id', 'customer-service.requests')->has('table.page.data', 1)->where('table.page.data.0.cells.subject', 'Second request')->where('table.page.data.0.record_link_column', 'subject'));
    $csv = $this->get('/service/export?'.http_build_query($state))->assertOk()->streamedContent();
    expect($csv)->toContain('Second request')->not->toContain('First request')->not->toContain('Hidden request');
    $this->post('/preferences', ['table' => 'customer-service.requests', 'columns' => ['subject', 'resolution', 'contact_email'], 'pinned' => ['subject']])->assertRedirect();
    $this->get('/service')->assertInertia(fn (Assert $page) => $page->where('table.preferences.columns', ['subject', 'resolution', 'contact_email']));
    $first = ServiceRequest::where('owner_id', $owner->id)->firstOrFail();
    for ($i = 0; $i < 9; $i++) {
        ServiceRequest::create([...$first->only(['contact_id', 'created_by', 'owner_id', 'description', 'received_on', 'channel', 'status', 'priority']), 'subject' => 'Additional request '.$i]);
    }
    $this->get('/service?per_page=10&page=2')->assertInertia(fn (Assert $page) => $page->where('table.page.current_page', 2)->has('table.page.data', 1));
    $owner->givePermissionTo('customer-service.view.all');
    $this->get('/service?q=Hidden')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('table.page.data.0.cells.contact', null)->where('table.page.data.0.cells.contact_id', null)->where('table.page.data.0.contact_id', null));
    $this->get('/service?q=yael-'.$other->id.'%40example.test')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 0));
    serviceDisable();
    $this->post('/preferences', ['table' => 'customer-service.requests', 'columns' => ['subject']])->assertSessionHasErrors('table');
    expect($owner->fresh()->display_preferences['tables']['customer-service.requests']['columns'])->toBe(['subject', 'resolution', 'contact_email']);
    $this->get('/service/export?'.http_build_query($state))->assertForbidden();
});
