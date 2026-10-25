<?php

use App\Models\User;
use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\IdentityRelationship;
use Awove\Core\Models\Routine;
use Awove\Core\Models\RoutineCompletion;
use Awove\Core\Models\WorkHistory;
use Awove\Core\Models\WorkItem;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkReminders;
use Awove\Operations\Models\OrderHistory;
use Awove\Operations\Models\OrderReceipt;
use Awove\Operations\Models\RequestDetails;
use Awove\Operations\Models\SupplierOrder;
use Awove\Operations\OperationsModule;
use Awove\Operations\Orders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->operationsManifest = tempnam(sys_get_temp_dir(), 'crm-operations-test-');
    $manifest = Installation::manifest();
    $manifest['modules'] = ['operations'];
    $manifest['capabilities']['sales'] = false;
    $manifest['timezone'] = 'Asia/Jerusalem';
    file_put_contents($this->operationsManifest, json_encode($manifest));
    config(['core.manifest' => $this->operationsManifest]);
    $this->artisan('crm:install')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-24T12:00:00+03:00'));
});

afterEach(function () {
    if (is_file($this->operationsManifest)) {
        unlink($this->operationsManifest);
    }
});

function opsUser(bool $all = false): User
{
    $user = User::factory()->create(['name' => 'Shira Levi']);
    $user->assignRole('Employee');
    $user->givePermissionTo($all ? (new OperationsModule)->permissions() : ['operations.view.own', 'operations.edit.own', 'operations.create', 'operations.archive', 'operations.export']);
    $user->givePermissionTo(['identities.classifications.view', 'identities.classifications.manage']);

    return $user;
}

function opsClassification(User $user, string $role = 'customer'): IdentityRelationship
{
    $model = $role === 'supplier' ? Company::class : Contact::class;
    $record = $model::create(['name' => $role === 'supplier' ? 'Oren Supply' : 'Yael Shalev', 'email' => $role.'-'.$user->id.'-'.Str::random(8).'@example.test', 'phone' => '+972 50 000 0000', 'search_key' => $role, 'created_by' => $user->id]);

    return app(IdentityRelationships::class)->create($user, [($role === 'supplier' ? 'company_id' : 'contact_id') => $record->id, 'role' => $role, 'responsible_id' => $user->id, 'active' => true, 'notes' => 'Fictional directory entry.']);
}

function opsOrderData(User $user, array $extra = []): array
{
    return [...['supplier_relationship_id' => opsClassification($user, 'supplier')->id, 'owner_id' => $user->id, 'reference' => 'PO-101', 'description' => 'Ten office supply units.', 'quantity' => '10.125', 'agreed_total' => '1200.50', 'ordered_on' => '2026-10-24', 'expected_on' => '2026-10-24', 'status' => 'ordered', 'document_url' => 'https://documents.example.test/order', 'outstanding_items' => null], ...$extra];
}

function opsReceiptData(int $version = 1, array $extra = []): array
{
    return [...['version' => $version, 'received_on' => '2026-10-24', 'quantity' => '2.005', 'complete' => false, 'description' => 'First office supplies delivered.', 'outstanding_items' => null, 'submission_key' => (string) Str::uuid()], ...$extra];
}

function opsRequestData(User $user, array $extra = []): array
{
    return [...['title' => 'Prepare office access', 'description' => null, 'owner_id' => $user->id, 'priority' => 'normal', 'due_on' => '2026-10-24', 'status' => 'new', 'customer_relationship_id' => opsClassification($user)->id, 'notes' => 'Confirm the contact details.', 'document_url' => 'https://documents.example.test/request'], ...$extra];
}

function opsDisable(): void
{
    $manifest = Installation::manifest();
    $manifest['modules'] = [];
    file_put_contents(config('core.manifest'), json_encode($manifest));
}

it('runs Operations with Service Sales and Finance disabled and retains versioned request completion history', function () {
    expect(app(Modules::class)->enabled('operations'))->toBeTrue()->and(app(Modules::class)->enabled('customer-service'))->toBeFalse()->and(app(Modules::class)->enabled('finance'))->toBeFalse()->and(app(Modules::class)->enabled('sales'))->toBeFalse();
    $user = opsUser();
    $data = opsRequestData($user);
    $this->actingAs($user)->post('/operations/requests', $data)->assertRedirect();
    $request = WorkItem::sole();
    expect($request->module)->toBe('operations')->and(RequestDetails::sole()->notes)->toBe('Confirm the contact details.');
    $this->patch('/operations/requests/'.$request->id, [...$data, 'status' => 'done', 'completed_on' => '2026-10-24', 'version' => 1])->assertRedirect();
    $this->patch('/operations/requests/'.$request->id, [...$data, 'status' => 'in-progress', 'version' => 2])->assertRedirect();
    expect($request->fresh()->completed_on)->toBeNull();
    expect(WorkHistory::latest('id')->first()->details['previous_completed_on'])->toBe('2026-10-24');
    $this->patch('/operations/requests/'.$request->id, [...$data, 'status' => 'in-progress', 'notes' => 'Updated notes only.', 'version' => 3])->assertRedirect();
    expect($request->fresh()->version)->toBe(4)->and(WorkHistory::latest('id')->first()->changed_fields)->toContain('module:operations:notes');
    $this->patch('/operations/requests/'.$request->id, [...$data, 'notes' => 'Stale overwrite.', 'version' => 3])->assertConflict();
    expect(RequestDetails::sole()->notes)->toBe('Updated notes only.');
    $this->get('/operations/requests/'.$request->id)->assertInertia(fn (Assert $page) => $page->where('record.customer.name', 'Yael Shalev')->has('history', 4));
    $this->get('/operations/requests/'.$request->id.'/document')->assertRedirect('https://documents.example.test/request');
    $this->post('/operations/requests', [...$data, 'title' => 'Internal task', 'customer_relationship_id' => null])->assertRedirect();
    expect(WorkItem::count())->toBe(2);
});

it('records exact partial order receipts with retry-safe keys and derived outstanding quantities', function () {
    $user = opsUser();
    $data = opsOrderData($user);
    $this->actingAs($user)->post('/operations/orders', $data)->assertRedirect();
    $order = SupplierOrder::sole();
    $receipt = opsReceiptData();
    $this->post('/operations/orders/'.$order->id.'/receipts', $receipt)->assertRedirect();
    $this->post('/operations/orders/'.$order->id.'/receipts', $receipt)->assertRedirect();
    expect(OrderReceipt::count())->toBe(1)->and($order->fresh()->status)->toBe('part-received')->and($order->fresh()->agreed_total)->toBe('1200.50');
    $this->get('/operations/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('record.received_quantity', '2.005')->where('record.outstanding_quantity', '8.120')->where('record.received_on', null)->has('receipts', 1));
    $this->post('/operations/orders/'.$order->id.'/receipts', [...$receipt, 'quantity' => '2.006'])->assertConflict();
    $this->post('/operations/orders/'.$order->id.'/receipts', opsReceiptData(2, ['quantity' => '8.120', 'received_on' => '2026-10-25']))->assertRedirect();
    expect($order->fresh()->status)->toBe('received')->and($order->fresh()->received_on->toDateString())->toBe('2026-10-25')->and(OrderReceipt::count())->toBe(2);
    $this->get('/operations/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('record.outstanding_quantity', '0.000'));
    expect(fn () => OrderReceipt::first()->update(['quantity' => '0.000']))->toThrow(LogicException::class);
    expect(OrderReceipt::first()->toArray())->not->toHaveKeys(['submission_key', 'submission_fingerprint']);
});

it('rejects over-receipts invalid dates state resets and changes to a received agreement without erasing history', function () {
    $user = opsUser();
    $data = opsOrderData($user);
    $order = app(Orders::class)->create($user, $data);
    $url = '/operations/orders/'.$order->id;
    $this->actingAs($user)->post($url.'/receipts', opsReceiptData(1, ['quantity' => '11.000']))->assertSessionHasErrors('quantity');
    $this->post($url.'/receipts', opsReceiptData(1, ['received_on' => '2026-10-23']))->assertSessionHasErrors('received_on');
    $this->post($url.'/receipts', opsReceiptData(1, ['quantity' => '0.000']))->assertSessionHasErrors('quantity');
    $this->post($url.'/receipts', opsReceiptData())->assertRedirect();
    $this->patch($url, [...$data, 'quantity' => '1.000', 'version' => 2])->assertSessionHasErrors('quantity');
    $this->patch($url, [...$data, 'ordered_on' => '2026-10-25', 'version' => 2])->assertSessionHasErrors('ordered_on');
    $this->patch($url, [...$data, 'status' => 'draft', 'version' => 2])->assertSessionHasErrors('status');
    $this->patch($url, [...$data, 'status' => 'cancelled', 'version' => 2])->assertRedirect();
    $this->post($url.'/receipts', opsReceiptData(3))->assertSessionHasErrors('received_on');
    expect(OrderReceipt::count())->toBe(1)->and($order->fresh()->status)->toBe('cancelled')->and($order->fresh()->quantity)->toBe('10.125');
    expect(OrderHistory::count())->toBe(3);
});

it('tracks unquantified service deliveries through explicit partial and complete receipts without fabricated quantities', function () {
    $user = opsUser();
    $order = app(Orders::class)->create($user, opsOrderData($user, ['quantity' => null]));
    $url = '/operations/orders/'.$order->id.'/receipts';
    $this->actingAs($user)->post($url, opsReceiptData(1, ['quantity' => null]))->assertSessionHasErrors('outstanding_items');
    $this->post($url, opsReceiptData(1, ['quantity' => null, 'outstanding_items' => 'Final installation remains.']))->assertRedirect();
    $this->get('/operations/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('record.received_quantity', null)->where('record.outstanding_quantity', null)->where('record.outstanding_items', 'Final installation remains.'));
    $this->post($url, opsReceiptData(2, ['quantity' => null, 'complete' => true, 'description' => 'Installation completed.']))->assertRedirect();
    expect($order->fresh()->status)->toBe('received')->and($order->fresh()->outstanding_items)->toBeNull()->and($order->fresh()->quantity)->toBeNull();
    expect(OrderHistory::where('event', 'receipt.recorded')->oldest('id')->first()->details)->toMatchArray(['received_on' => '2026-10-24', 'outstanding_items' => 'Final installation remains.']);
});

it('separates own all-read edit assignment and classification permissions across direct routes and exports', function () {
    $owner = opsUser();
    $other = opsUser();
    $order = app(Orders::class)->create($owner, opsOrderData($owner));
    $work = app(WorkItems::class)->create($owner, 'operations', 'requests', ['title' => 'Private request', 'due_on' => '2026-10-24']);
    $routine = app(Routines::class)->create($owner, 'operations', 'routines', ['name' => 'Private routine', 'frequency' => 'Weekly', 'next_due_on' => '2026-10-24', 'checklist' => []]);
    $this->actingAs($other)->get('/operations/orders/'.$order->id)->assertForbidden();
    $this->get('/operations/orders/'.$order->id.'/document')->assertForbidden();
    $this->post('/operations/orders/'.$order->id.'/receipts', opsReceiptData())->assertForbidden();
    $this->get('/operations/requests/'.$work->id)->assertNotFound();
    $this->get('/operations/routines/'.$routine->id)->assertNotFound();
    $this->get('/operations/orders')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 0));
    expect($this->get('/operations/orders/export')->assertOk()->streamedContent())->not->toContain('PO-101');
    $other->givePermissionTo('operations.view.all');
    $this->get('/operations/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('record.editable', false)->where('record.supplier', null)->where('record.supplier_relationship_id', null));
    $this->patch('/operations/orders/'.$order->id, [])->assertForbidden();
    $this->get('/operations/orders?q=Oren')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 0));
    $this->get('/operations/orders?q=PO-101')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('table.page.data.0.cells.supplier_id', null));
    $other->givePermissionTo('operations.edit.all');
    $data = $order->toArray();
    $data['version'] = 1;
    $data['supplier_relationship_id'] = null;
    $this->patch('/operations/orders/'.$order->id, [...$data, 'description' => 'Permitted edit retaining hidden supplier.'])->assertRedirect();
    expect($order->fresh()->supplier_relationship_id)->not->toBeNull();
});

it('retains inaccessible customer and supplier relationships during unrelated edits and rejects forged choices', function () {
    $user = opsUser();
    $other = opsUser();
    $customer = opsClassification($user);
    $hidden = opsClassification($other, 'supplier');
    $this->actingAs($user)->post('/operations/orders', opsOrderData($user, ['supplier_relationship_id' => $hidden->id]))->assertForbidden();
    $this->post('/operations/orders', opsOrderData($user, ['supplier_relationship_id' => $customer->id]))->assertForbidden();
    $data = opsRequestData($user, ['customer_relationship_id' => $customer->id]);
    $this->post('/operations/requests', $data)->assertRedirect();
    $request = WorkItem::sole();
    $user->revokePermissionTo(['identities.classifications.view', 'identities.classifications.manage']);
    $this->get('/operations/requests/'.$request->id)->assertInertia(fn (Assert $page) => $page->where('record.customer', null)->where('record.customer_relationship_id', null));
    $this->patch('/operations/requests/'.$request->id, [...$data, 'customer_relationship_id' => null, 'notes' => 'Retain linked customer.', 'version' => 1])->assertRedirect();
    expect(RequestDetails::sole()->customer_relationship_id)->toBe($customer->id);
    $this->get('/operations/directory')->assertForbidden();
});

it('shares one directory with scoped contact details main contacts change history and linked business records', function () {
    $user = opsUser();
    $other = opsUser();
    $company = Company::create(['name' => 'Carmel Supply', 'email' => 'carmel@example.test', 'phone' => '+972 50 000 0000', 'created_by' => $user->id, 'search_key' => 'carmel']);
    $contact = Contact::create(['name' => 'Noam Erez', 'company_id' => $company->id, 'created_by' => $user->id, 'search_key' => 'noam']);
    $this->actingAs($user)->post('/operations/directory', ['company_id' => $company->id, 'role' => 'supplier', 'main_contact_id' => $contact->id, 'responsible_id' => $user->id, 'active' => true, 'notes' => 'Local supplier.'])->assertRedirect();
    $relationship = IdentityRelationship::sole();
    $this->post('/operations/orders', opsOrderData($user, ['supplier_relationship_id' => $relationship->id]))->assertRedirect();
    $this->get('/operations/directory/'.$relationship->id)->assertInertia(fn (Assert $page) => $page->where('record.main_contact.name', 'Noam Erez')->has('related.orders', 1)->has('related.opportunities', 0));
    $company->update(['phone' => '+972 50 000 0001']);
    $this->get('/operations/directory/'.$relationship->id)->assertInertia(fn (Assert $page) => $page->where('record.phone', '+972 50 000 0001'));
    $this->patch('/operations/directory/'.$relationship->id, ['version' => 1, 'notes' => 'Updated supplier note.'])->assertRedirect();
    $this->patch('/operations/directory/'.$relationship->id, ['version' => 1, 'notes' => 'Stale note.'])->assertSessionHasErrors('version');
    $this->get('/operations/directory/'.$relationship->id)->assertInertia(fn (Assert $page) => $page->has('history', 2));
    $hiddenCompany = Company::create(['name' => 'Hidden company', 'created_by' => $other->id, 'search_key' => 'hidden']);
    Contact::create(['name' => 'Visible contact', 'company_id' => $hiddenCompany->id, 'created_by' => $user->id, 'search_key' => 'visible']);
    $this->get('/operations/directory')->assertInertia(fn (Assert $page) => $page->where('contacts.1.company_id', null));
    $this->post('/operations/directory/'.$relationship->id.'/archive', ['version' => 2, 'archived' => true])->assertRedirect();
    $this->get('/operations/directory?archived=1')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1));
    $this->post('/operations/directory/'.$relationship->id.'/archive', ['version' => 3, 'archived' => false])->assertRedirect();
    expect(Company::count())->toBe(3);
});

it('retains manual routine occurrences checklists and explicit next dates without generating recurring work', function () {
    $user = opsUser();
    $data = ['name' => 'Office readiness review', 'owner_id' => $user->id, 'frequency' => 'Weekly', 'next_due_on' => '2026-10-24', 'checklist' => ['Check equipment', 'Review supplies']];
    $this->actingAs($user)->post('/operations/routines', $data)->assertRedirect();
    $routine = Routine::sole();
    $this->post('/operations/routines/'.$routine->id.'/complete', ['version' => 1, 'completed_on' => '2026-10-24', 'checked_items' => ['Check equipment'], 'next_due_on' => '2026-10-31'])->assertRedirect();
    $this->post('/operations/routines/'.$routine->id.'/complete', ['version' => 1, 'completed_on' => '2026-10-24'])->assertConflict();
    $this->patch('/operations/routines/'.$routine->id, [...$data, 'checklist' => ['Replacement checklist'], 'next_due_on' => '2026-10-31', 'version' => 2])->assertRedirect();
    $this->get('/operations/routines/'.$routine->id)->assertInertia(fn (Assert $page) => $page->has('completions', 1)->where('completions.0.checklist_snapshot.0.label', 'Check equipment')->where('completions.0.checklist_snapshot.0.completed', true)->where('completions.0.checklist_snapshot.1.completed', false));
    $this->get('/operations/routines?view=upcoming')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1));
    $this->get('/operations/routines?view=overdue')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 0));
    $this->post('/operations/routines/'.$routine->id.'/complete', ['version' => 3, 'completed_on' => '2026-10-31', 'checked_items' => [], 'next_due_on' => null])->assertRedirect();
    expect($routine->fresh()->next_due_on)->toBeNull()->and(Routine::count())->toBe(1)->and(RoutineCompletion::count())->toBe(2);
    $this->get('/operations/routines?view=upcoming')->assertInertia(fn (Assert $page) => $page->has('table.page.data', 0));
    $this->post('/operations/routines/'.$routine->id.'/archive', ['version' => 4])->assertRedirect();
    expect(RoutineCompletion::count())->toBe(2);
});

it('suppresses obsolete work routine and order reminders after rescheduling completion assignment archive and access loss', function () {
    $user = opsUser();
    $other = opsUser();
    $work = app(WorkItems::class)->create($user, 'operations', 'requests', ['title' => 'Call supplier', 'due_on' => '2026-10-24']);
    $routine = app(Routines::class)->create($user, 'operations', 'routines', ['name' => 'Office check', 'frequency' => 'Weekly', 'next_due_on' => '2026-10-24', 'checklist' => []]);
    $order = app(Orders::class)->create($user, opsOrderData($user));
    app(WorkReminders::class)->enqueueDue();
    app(WorkReminders::class)->enqueueDue();
    expect(WorkReminder::count())->toBe(3);
    $notices = app(WorkReminders::class)->forUser($user);
    expect($notices)->toHaveCount(3);
    $this->actingAs($user)->post('/work-reminders/'.$notices[0]['id'].'/read')->assertRedirect();
    expect($order->fresh()->status)->toBe('ordered')->and($work->fresh()->status)->toBe('new');
    app(WorkItems::class)->update($user, 'operations', 'requests', $work->id, ['version' => 1, 'due_on' => '2026-10-26']);
    app(Routines::class)->complete($user, 'operations', 'routines', $routine->id, ['version' => 1, 'completed_on' => '2026-10-24', 'checked_items' => [], 'next_due_on' => null]);
    $this->post('/operations/orders/'.$order->id.'/receipts', opsReceiptData(1, ['quantity' => '10.125']))->assertRedirect();
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    $job = new DeliverWorkReminder('operations', 'orders', $order->id, 1, $user->id);
    $job->handle();
    expect(WorkReminder::count())->toBe(3);
    $otherOrder = app(Orders::class)->create($user, opsOrderData($user, ['reference' => 'PO-102']));
    $user->givePermissionTo('operations.assign');
    $data = $otherOrder->toArray();
    $data['version'] = 1;
    $data['owner_id'] = $other->id;
    $this->patch('/operations/orders/'.$otherOrder->id, $data)->assertRedirect();
    (new DeliverWorkReminder('operations', 'orders', $otherOrder->id, 1, $user->id))->handle();
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    $user->revokePermissionTo('operations.view.own');
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
});

it('denies every disabled module route preserves records and prevents stale notification previews and delivery', function () {
    $user = opsUser();
    $order = app(Orders::class)->create($user, opsOrderData($user));
    $this->actingAs($user)->post('/operations/requests', opsRequestData($user))->assertRedirect();
    $work = WorkItem::sole();
    $routine = app(Routines::class)->create($user, 'operations', 'routines', ['name' => 'Daily check', 'frequency' => 'Daily', 'next_due_on' => '2026-10-24', 'checklist' => []]);
    $relationship = IdentityRelationship::first();
    app(WorkReminders::class)->enqueueDue();
    $count = WorkReminder::count();
    opsDisable();
    foreach (['/operations', '/operations/requests', '/operations/orders', '/operations/routines', '/operations/directory', '/operations/requests/'.$work->id, '/operations/orders/'.$order->id, '/operations/routines/'.$routine->id, '/operations/directory/'.$relationship->id, '/operations/orders/'.$order->id.'/document', '/operations/requests/'.$work->id.'/document', '/operations/orders/export', '/operations/requests/export', '/operations/routines/export', '/operations/directory/export'] as $url) {
        $this->get($url)->assertForbidden();
    }
    foreach (['/operations/requests', '/operations/orders', '/operations/routines', '/operations/directory', '/operations/orders/'.$order->id.'/receipts', '/operations/routines/'.$routine->id.'/complete', '/operations/requests/'.$work->id.'/archive', '/operations/orders/'.$order->id.'/archive', '/operations/routines/'.$routine->id.'/archive', '/operations/directory/'.$relationship->id.'/archive'] as $url) {
        $this->post($url, [])->assertForbidden();
    }
    $this->patch('/operations/orders/'.$order->id, [])->assertForbidden();
    $this->patch('/operations/requests/'.$work->id, [])->assertForbidden();
    $this->patch('/operations/routines/'.$routine->id, [])->assertForbidden();
    $this->patch('/operations/directory/'.$relationship->id, [])->assertForbidden();
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    (new DeliverWorkReminder('operations', 'orders', $order->id, 1, $user->id))->handle();
    expect(WorkReminder::count())->toBe($count)->and(SupplierOrder::count())->toBe(1)->and(WorkItem::count())->toBe(1)->and(Routine::count())->toBe(1);
    $manifest = Installation::manifest();
    $manifest['modules'] = ['operations'];
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $this->get('/operations/orders/'.$order->id)->assertOk();
});

it('uses scoped advanced tables and formula-safe exports with matching filters and configurable status labels', function () {
    $user = opsUser();
    $other = opsUser();
    $this->actingAs($user)->post('/operations/requests', opsRequestData($user, ['title' => '=SUM(1,2)', 'status' => 'waiting', 'priority' => 'high']))->assertRedirect();
    app(WorkItems::class)->create($other, 'operations', 'requests', ['title' => 'Hidden request', 'due_on' => '2026-10-24', 'priority' => 'high']);
    $manifest = Installation::manifest();
    $manifest['operations_request_statuses'] = ['waiting' => 'Awaiting input'];
    $manifest['operations_order_statuses'] = ['ordered' => 'Placed'];
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $query = http_build_query(['filters' => [['id' => 'priority', 'op' => 'eq', 'value' => 'high']], 'sorts' => [['id' => 'title', 'direction' => 'desc']]]);
    $this->get('/operations/requests?'.$query)->assertInertia(fn (Assert $page) => $page->has('table.page.data', 1)->where('statuses.waiting', 'Awaiting input'));
    $csv = $this->get('/operations/requests/export?'.$query)->assertOk()->streamedContent();
    expect($csv)->toContain("'=SUM(1,2)")->toContain('Awaiting input')->not->toContain('Hidden request');
    $this->post('/preferences', ['table' => 'operations.requests', 'columns' => ['customer', 'title', 'notes', 'completed_on'], 'pinned' => ['customer']])->assertRedirect();
    $this->get('/operations/requests')->assertInertia(fn (Assert $page) => $page->where('table.preferences.columns', ['customer', 'title', 'notes', 'completed_on']));
    $this->get('/operations?to=2026-10-24')->assertInertia(fn (Assert $page) => $page->where('metrics.open_requests', 1));
    $user->revokePermissionTo('operations.export');
    $this->get('/operations/requests/export')->assertForbidden();
});

it('keeps staff grants explicit rejects unsafe documents and exact monetary input and checks stale actors before idempotent replay', function () {
    $ungranted = User::factory()->create();
    $ungranted->assignRole('Employee');
    expect($ungranted->can('operations.view.own'))->toBeFalse();
    $this->actingAs($ungranted)->get('/operations')->assertForbidden();
    $this->post('/operations/orders', [])->assertForbidden();
    $user = opsUser();
    $data = opsOrderData($user);
    foreach (['javascript:alert(1)', 'https://person:secret@documents.example.test/file', 'http://documents.example.test/file'] as $url) {
        $this->actingAs($user)->post('/operations/orders', [...$data, 'document_url' => $url])->assertSessionHasErrors('document_url');
    }
    $this->post('/operations/orders', [...$data, 'agreed_total' => 12.5])->assertSessionHasErrors('agreed_total');
    $this->post('/operations/orders', $data)->assertRedirect();
    $order = SupplierOrder::sole();
    $receipt = opsReceiptData();
    $this->post('/operations/orders/'.$order->id.'/receipts', $receipt)->assertRedirect();
    $stale = $user->fresh();
    $user->forceFill(['active' => false])->save();
    expect(fn () => app(Orders::class)->receipt($stale, $order->id, $receipt))->toThrow(HttpException::class);
    expect(OrderReceipt::count())->toBe(1);
});

it('retains reassignment and supplier change snapshots while redacting inaccessible historical supplier IDs', function () {
    $owner = opsUser(true);
    $replacement = opsUser();
    $data = opsOrderData($owner);
    $order = app(Orders::class)->create($owner, $data);
    $newSupplier = opsClassification($owner, 'supplier');
    $this->actingAs($owner)->patch('/operations/orders/'.$order->id, [...$data, 'supplier_relationship_id' => $newSupplier->id, 'owner_id' => $replacement->id, 'ordered_on' => '2026-10-23', 'version' => 1])->assertRedirect();
    $history = OrderHistory::where('event', 'order.updated')->sole()->details;
    expect($history['before'])->toMatchArray(['supplier_relationship_id' => $data['supplier_relationship_id'], 'owner_id' => $owner->id]);
    expect($history['after'])->toMatchArray(['supplier_relationship_id' => $newSupplier->id, 'owner_id' => $replacement->id]);
    expect($history['after']['ordered_on'])->toStartWith('2026-10-23');
    $this->actingAs($replacement)->get('/operations/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('history.0.details.before.supplier_relationship_id', null)->where('history.0.details.after.supplier_relationship_id', null));
});

it('preserves order currency snapshots and blocks monetary agreement changes after installation currency changes', function () {
    $user = opsUser();
    $data = opsOrderData($user);
    $orders = app(Orders::class);
    $order = $orders->create($user, $data);
    $originalCurrency = Currency::current();
    expect($order->getAttribute('currency'))->toBe($originalCurrency)
        ->and(app(Currency::class)->consistent())->toBeTrue();
    $historyCount = OrderHistory::count();
    $manifest = Installation::manifest();
    $manifest['currency'] = $originalCurrency === 'USD' ? 'EUR' : 'USD';
    file_put_contents($this->operationsManifest, json_encode($manifest));
    expect(app(Currency::class)->consistent())->toBeFalse()
        ->and(fn () => $orders->create($user, [...$data, 'reference' => 'PO-102']))->toThrow(ValidationException::class)
        ->and(fn () => $orders->update($user, $order->id, [...$data, 'version' => 1, 'agreed_total' => '999.99']))->toThrow(ValidationException::class)
        ->and(SupplierOrder::count())->toBe(1)
        ->and($order->fresh()->agreed_total)->toBe('1200.50')
        ->and($order->fresh()->getAttribute('currency'))->toBe($originalCurrency)
        ->and(OrderHistory::count())->toBe($historyCount);
    $this->actingAs($user)->get('/operations/orders/'.$order->id)->assertInertia(fn (Assert $page) => $page->where('currencyError', Currency::ERROR)->where('record.currency', $originalCurrency));
    $csv = $this->actingAs($user)->get('/operations/orders/export')->assertOk()->streamedContent();
    expect($csv)->toContain('1200.50')->toContain($originalCurrency);
    // Delivery receipts remain physical records, with no currency conversion or payable creation.
    $receipt = $orders->receipt($user, $order->id, opsReceiptData());
    expect($receipt->quantity)->toBe('2.005')->and($order->fresh()->agreed_total)->toBe('1200.50');
});

it('includes retained disabled Operations orders in the shared currency guard without blocking ordinary work', function () {
    $user = opsUser();
    $order = app(Orders::class)->create($user, opsOrderData($user));
    app(Orders::class)->archive($user, $order->id, ['version' => 1]);
    $manifest = Installation::manifest();
    $manifest['modules'] = [];
    $manifest['currency'] = Currency::current() === 'USD' ? 'EUR' : 'USD';
    file_put_contents($this->operationsManifest, json_encode($manifest));
    expect(app(Modules::class)->enabled('operations'))->toBeFalse()
        ->and(app(Currency::class)->consistent())->toBeFalse()
        ->and(fn () => app(Currency::class)->assertConsistent())->toThrow(ValidationException::class)
        ->and($order->fresh()->agreed_total)->toBe('1200.50');
    $manifest['modules'] = ['operations'];
    file_put_contents($this->operationsManifest, json_encode($manifest));
    $work = app(WorkItems::class)->create($user, 'operations', 'requests', ['title' => 'Review fictional delivery', 'due_on' => '2026-10-24']);
    expect($work->title)->toBe('Review fictional delivery')->and(app(Currency::class)->consistent())->toBeFalse();
    $manifest['currency'] = $order->getAttribute('currency');
    file_put_contents($this->operationsManifest, json_encode($manifest));
    expect(app(Currency::class)->consistent())->toBeTrue();
});
