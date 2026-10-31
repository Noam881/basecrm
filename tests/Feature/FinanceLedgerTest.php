<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Services\WorkReminders;
use Awove\Finance\Currency;
use Awove\Finance\Ledger;
use Awove\Finance\LedgerCapability;
use Awove\Finance\Models\LedgerHistory;
use Awove\Finance\Models\LedgerRecord;
use Awove\Finance\Models\Payment;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $manifest = Installation::manifest();
    $manifest['modules'] = ['finance'];
    $manifest['capabilities'] = ['sales' => false, 'finance:collections' => true, 'finance:expenses' => true, 'finance:payroll' => false];
    $manifest['timezone'] = 'Asia/Jerusalem';
    $path = tempnam(sys_get_temp_dir(), 'finance-ledger-');
    file_put_contents($path, json_encode($manifest));
    config(['core.manifest' => $path]);
    $this->artisan('crm:install')->assertSuccessful();
    $this->travelTo(new DateTimeImmutable('2026-10-24T12:00:00+03:00'));
});
afterEach(function () {
    @unlink(config('core.manifest'));
});
function flUser(string $cap = 'collections', bool $all = false): User
{
    $user = User::factory()->create();
    $user->assignRole('Employee');
    $permissions = (new LedgerCapability($cap))->permissions();
    if (! $all) {
        $permissions = array_values(array_filter($permissions, fn ($p) => ! in_array($p, ['finance.'.$cap.'.view.all', 'finance.'.$cap.'.edit.all', 'finance.'.$cap.'.assign'], true)));
    }$user->givePermissionTo([...$permissions, 'identities.classifications.view', 'identities.classifications.manage']);

    return $user;
}
function flData(User $user, string $cap = 'collections', array $extra = []): array
{
    $identity = $cap === 'collections' ? Contact::create(['name' => 'Yael Shalev', 'email' => Str::uuid().'@example.test', 'phone' => '+1 202-555-0101', 'search_key' => 'yaelshalev', 'created_by' => $user->id]) : Company::create(['name' => 'Carmel Supply', 'email' => Str::uuid().'@example.test', 'phone' => '+1 202-555-0102', 'search_key' => 'carmelsupply', 'created_by' => $user->id]);
    $role = $cap === 'collections' ? 'customer' : 'supplier';
    $relationship = app(IdentityRelationships::class)->create($user, [$cap === 'collections' ? 'contact_id' : 'company_id' => $identity->id, 'role' => $role, 'responsible_id' => $user->id]);

    return [...['relationship_id' => $relationship->id, 'reference' => $cap === 'collections' ? 'CH-101' : 'EXP-101', 'description' => 'Agreed professional services.', 'category' => $cap === 'expenses' ? 'Professional services' : null, 'total' => '1000.10', 'owner_id' => $user->id, 'due_on' => '2026-10-24', 'document_url' => 'https://documents.example.test/agreement', 'next_action' => $cap === 'collections' ? 'Confirm the payment date.' : null, 'next_action_on' => $cap === 'collections' ? '2026-10-24' : null], ...$extra];
}
function flPayment(int $version = 1, array $extra = []): array
{
    return [...['version' => $version, 'amount' => '333.33', 'paid_on' => '2026-10-24', 'reference' => 'PAY-101', 'notes' => 'Payment recorded after checking the agreed record.', 'submission_key' => (string) Str::uuid()], ...$extra];
}
function flSetCap(string $cap, bool $enabled): void
{
    $manifest = Installation::manifest();
    $manifest['capabilities']['finance:'.$cap] = $enabled;
    file_put_contents(config('core.manifest'), json_encode($manifest));
}
it('runs collections and expenses without Sales Operations HR or payroll and derives exact retained balances', function () {
    $user = flUser();
    $user->givePermissionTo((new LedgerCapability('expenses'))->permissions());
    expect(app(Modules::class)->enabled('finance:collections'))->toBeTrue()->and(app(Modules::class)->enabled('sales'))->toBeFalse()->and(app(Modules::class)->enabled('operations'))->toBeFalse()->and(app(Modules::class)->enabled('hr'))->toBeFalse();
    $data = flData($user);
    $this->actingAs($user)->post('/finance/collections', $data)->assertRedirect();
    $record = LedgerRecord::sole();
    $this->get('/finance/collections/'.$record->id)->assertInertia(fn (Assert $p) => $p->where('record.total', '1000.10')->where('record.paid_amount', '0.00')->where('record.remaining_amount', '1000.10')->where('record.status', 'unpaid'));
    $this->post('/finance/collections/'.$record->id.'/payments', flPayment())->assertRedirect();
    $this->get('/finance/collections/'.$record->id)->assertInertia(fn (Assert $p) => $p->where('record.remaining_amount', '666.77')->where('record.status', 'part-paid')->has('payments', 1));
    $this->post('/finance/collections/'.$record->id.'/payments', flPayment(2, ['amount' => '666.77']))->assertRedirect();
    $this->get('/finance/collections/'.$record->id)->assertInertia(fn (Assert $p) => $p->where('record.remaining_amount', '0.00')->where('record.paid_amount', '1000.10')->where('record.status', 'paid')->has('history', 3));
    $this->post('/finance/expenses', flData($user, 'expenses'))->assertRedirect();
    expect(LedgerRecord::count())->toBe(2)->and(Payment::count())->toBe(2);
});
it('records idempotent append-only payments and rejects altered retries stale writes overpayments refunds and forged status', function () {
    $user = flUser();
    $data = flData($user);
    $record = app(Ledger::class)->create($user, 'collections', $data);
    $url = '/finance/collections/'.$record->id;
    $input = flPayment();
    $this->actingAs($user)->post($url.'/payments', $input)->assertRedirect();
    $this->post($url.'/payments', $input)->assertRedirect();
    expect(Payment::count())->toBe(1)->and(LedgerHistory::count())->toBe(2)->and($record->fresh()->version)->toBe(2);
    $this->post($url.'/payments', [...$input, 'amount' => '333.34'])->assertConflict();
    $this->post($url.'/payments', flPayment(1))->assertConflict();
    $this->post($url.'/payments', flPayment(2, ['amount' => '666.78']))->assertSessionHasErrors('amount');
    $this->post($url.'/payments', flPayment(2, ['amount' => '-1.00']))->assertSessionHasErrors('amount');
    $this->post($url.'/payments', flPayment(2, ['amount' => '0.00']))->assertSessionHasErrors('amount');
    $this->post($url.'/payments', flPayment(2, ['amount' => 0.1]))->assertSessionHasErrors('amount');
    $this->patch($url, [...$data, 'version' => 2, 'status' => 'paid'])->assertSessionHasErrors('status');
    expect(fn () => Payment::sole()->update(['amount' => '1.00']))->toThrow(LogicException::class);
    expect(fn () => Payment::sole()->delete())->toThrow(LogicException::class);
    expect(Payment::sole()->toArray())->not->toHaveKeys(['submission_key', 'submission_fingerprint']);
});
it('freezes financial agreements after payment while retaining useful edits and rejects cancellation after payment', function () {
    $user = flUser();
    $data = flData($user);
    $record = app(Ledger::class)->create($user, 'collections', $data);
    $url = '/finance/collections/'.$record->id;
    $this->actingAs($user)->post($url.'/payments', flPayment())->assertRedirect();
    $this->patch($url, [...$data, 'total' => '900.00', 'version' => 2])->assertSessionHasErrors('total');
    $other = flData($user);
    $this->patch($url, [...$data, 'relationship_id' => $other['relationship_id'], 'version' => 2])->assertSessionHasErrors('total');
    $this->patch($url, [...$data, 'due_on' => '2026-10-31', 'description' => 'Updated explanation.', 'version' => 2])->assertRedirect();
    $this->post($url.'/cancel', ['version' => 3, 'cancellation_reason' => 'Change agreement.'])->assertSessionHasErrors('cancellation_reason');
    $this->get($url)->assertInertia(fn (Assert $p) => $p->where('record.total', '1000.10')->where('record.due_on', '2026-10-31')->where('record.paid_amount', '333.33')->has('history', 3));
});
it('cancels an unpaid record without claiming payment erasing its agreement or accepting later payments', function () {
    $user = flUser();
    $record = app(Ledger::class)->create($user, 'collections', flData($user));
    $url = '/finance/collections/'.$record->id;
    $this->actingAs($user)->post($url.'/cancel', ['version' => 1, 'cancellation_reason' => 'Agreement withdrawn before payment.'])->assertRedirect();
    $this->get($url)->assertInertia(fn (Assert $p) => $p->where('record.status', 'cancelled')->where('record.remaining_amount', '1000.10')->where('record.editable', false));
    $this->post($url.'/payments', flPayment(2))->assertSessionHasErrors('amount');
    $this->get('/finance/collections?view=outstanding')->assertInertia(fn (Assert $p) => $p->has('table.page.data', 0));
    $this->get('/finance')->assertInertia(fn (Assert $p) => $p->where('metrics.collections.expected', '0.00')->where('metrics.collections.actual', '0.00'));
    expect(Payment::count())->toBe(0)->and(LedgerHistory::count())->toBe(2);
});
it('keeps due-date expected balances distinct from actual payment-date amounts for receipts and supplier expenses', function () {
    $user = flUser();
    $user->givePermissionTo((new LedgerCapability('expenses'))->permissions());
    $charge = app(Ledger::class)->create($user, 'collections', flData($user, 'collections', ['due_on' => '2026-01-15']));
    $expense = app(Ledger::class)->create($user, 'expenses', flData($user, 'expenses', ['total' => '250.00', 'due_on' => '2026-01-25']));
    app(Ledger::class)->payment($user, 'collections', $charge->id, flPayment(1, ['paid_on' => '2026-02-05']));
    app(Ledger::class)->payment($user, 'expenses', $expense->id, flPayment(1, ['amount' => '50.10', 'paid_on' => '2026-02-06']));
    $this->actingAs($user)->get('/finance?from=2026-01-01&to=2026-01-31')->assertInertia(fn (Assert $p) => $p->where('metrics.collections.expected', '666.77')->where('metrics.collections.actual', '0.00')->where('metrics.expenses.expected', '199.90')->where('metrics.expenses.actual', '0.00'));
    $this->get('/finance?from=2026-02-01&to=2026-02-28')->assertInertia(fn (Assert $p) => $p->where('metrics.collections.expected', '0.00')->where('metrics.collections.actual', '333.33')->where('metrics.expenses.expected', '0.00')->where('metrics.expenses.actual', '50.10'));
    $this->get('/finance?to=2026-02-05')->assertInertia(fn (Assert $p) => $p->where('metrics.collections.actual', '333.33')->where('metrics.expenses.actual', '0.00'));
});
it('separates own all-read edit payment cancellation assignment and export permissions', function () {
    $owner = flUser();
    $other = flUser();
    $data = flData($owner);
    $record = app(Ledger::class)->create($owner, 'collections', $data);
    $url = '/finance/collections/'.$record->id;
    $this->actingAs($other)->get($url)->assertNotFound();
    $this->get($url.'/document')->assertNotFound();
    $this->post($url.'/payments', flPayment())->assertNotFound();
    expect($this->get('/finance/collections/export')->assertOk()->streamedContent())->not->toContain('CH-101');
    $other->givePermissionTo('finance.collections.view.all');
    $this->get($url)->assertInertia(fn (Assert $p) => $p->where('record.editable', false)->where('record.relationship', null)->where('record.relationship_id', null));
    $this->patch($url, [...$data, 'version' => 1])->assertForbidden();
    $other->givePermissionTo('finance.collections.edit.all');
    $other->revokePermissionTo(['finance.collections.record-payment', 'finance.collections.cancel', 'finance.collections.export']);
    $this->post($url.'/payments', flPayment())->assertForbidden();
    $this->post($url.'/cancel', ['version' => 1, 'cancellation_reason' => 'No longer needed'])->assertForbidden();
    $this->get('/finance/collections/export')->assertForbidden();
    $this->patch($url, [...$data, 'relationship_id' => null, 'description' => 'Permitted edit retaining hidden customer.', 'version' => 1])->assertRedirect();
    expect($record->fresh()->relationship_id)->toBe($data['relationship_id']);
    $this->patch($url, [...$data, 'owner_id' => $other->id, 'version' => 2])->assertForbidden();
});
it('creates shared customer and supplier classifications within intake without Operations or duplicate identities', function () {
    $user = flUser();
    $user->givePermissionTo((new LedgerCapability('expenses'))->permissions());
    $contact = Contact::create(['name' => 'Noam Erez', 'email' => 'noam@example.test', 'search_key' => 'noamerez', 'created_by' => $user->id]);
    $company = Company::create(['name' => 'Oren Supply', 'email' => 'oren@example.test', 'search_key' => 'orensupply', 'created_by' => $user->id]);
    $this->actingAs($user)->post('/finance/collections/classifications', ['contact_id' => $contact->id, 'active' => true, 'notes' => 'Existing shared identity.'])->assertRedirect();
    $this->post('/finance/expenses/classifications', ['company_id' => $company->id, 'active' => true])->assertRedirect();
    expect(Contact::count())->toBe(1)->and(Company::count())->toBe(1);
    $this->get('/finance/collections')->assertInertia(fn (Assert $p) => $p->has('relationships', 1)->where('relationships.0.name', 'Noam Erez'));
    $this->get('/finance/expenses')->assertInertia(fn (Assert $p) => $p->has('relationships', 1)->where('relationships.0.name', 'Oren Supply'));
    $user->revokePermissionTo('identities.classifications.manage');
    $this->post('/finance/collections/classifications', ['company_id' => $company->id])->assertForbidden();
});
it('redacts hidden identities from selectors search CSV and historical changes while retaining links on unrelated edits', function () {
    $owner = flUser();
    $reader = flUser();
    $data = flData($owner);
    $record = app(Ledger::class)->create($owner, 'collections', $data);
    $owner->givePermissionTo('finance.collections.assign');
    $this->actingAs($owner)->patch('/finance/collections/'.$record->id, [...$data, 'owner_id' => $reader->id, 'version' => 1])->assertRedirect();
    $this->actingAs($reader)->get('/finance/collections/'.$record->id)->assertInertia(fn (Assert $p) => $p->where('record.relationship', null)->where('record.relationship_id', null)->where('history.0.details.before.relationship_id', null)->where('history.0.details.after.relationship_id', null));
    $this->get('/finance/collections?q=Yael')->assertInertia(fn (Assert $p) => $p->has('table.page.data', 0));
    expect($this->get('/finance/collections/export')->streamedContent())->not->toContain('Yael');
    $this->patch('/finance/collections/'.$record->id, [...$data, 'relationship_id' => null, 'owner_id' => $reader->id, 'description' => 'Preserve historical relationship.', 'version' => 2])->assertRedirect();
    expect($record->fresh()->relationship_id)->toBe($data['relationship_id']);
    $hidden = flData($owner);
    $this->post('/finance/collections', [...$hidden, 'owner_id' => $reader->id])->assertForbidden();
});
it('gates each capability independently across routes tables exports documents search reminders and mutations without losing records', function () {
    $user = flUser();
    $user->givePermissionTo((new LedgerCapability('expenses'))->permissions());
    $charge = app(Ledger::class)->create($user, 'collections', flData($user));
    $expense = app(Ledger::class)->create($user, 'expenses', flData($user, 'expenses'));
    app(WorkReminders::class)->enqueueDue();
    expect(WorkReminder::count())->toBe(2);
    flSetCap('collections', false);
    $this->actingAs($user);
    foreach (['/finance/collections', '/finance/collections/export', '/finance/collections/'.$charge->id, '/finance/collections/'.$charge->id.'/document'] as $url) {
        $this->get($url)->assertForbidden();
    }$this->post('/finance/collections/'.$charge->id.'/payments', flPayment())->assertForbidden();
    $this->post('/finance/collections/classifications', [])->assertForbidden();
    $this->get('/finance/expenses/'.$expense->id)->assertOk();
    $this->get('/finance')->assertInertia(fn (Assert $p) => $p->missing('metrics.collections')->has('metrics.expenses'));
    expect(app(WorkReminders::class)->forUser($user))->toHaveCount(1);
    (new DeliverWorkReminder('finance', 'collections', $charge->id, 1, $user->id))->handle(app(WorkReminders::class));
    expect(WorkReminder::count())->toBe(2);
    flSetCap('collections', true);
    $this->get('/finance/collections/'.$charge->id)->assertOk();
    expect(LedgerRecord::count())->toBe(2);
});
it('disables the whole Finance module preserves payments and stops stale queued work and previews', function () {
    $user = flUser();
    $record = app(Ledger::class)->create($user, 'collections', flData($user));
    $input = flPayment();
    app(Ledger::class)->payment($user, 'collections', $record->id, $input);
    app(WorkReminders::class)->enqueueDue();
    $manifest = Installation::manifest();
    $manifest['modules'] = [];
    $manifest['capabilities']['finance:collections'] = false;
    $manifest['capabilities']['finance:expenses'] = false;
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $this->actingAs($user)->get('/finance')->assertForbidden();
    $this->get('/finance/collections/'.$record->id)->assertForbidden();
    $this->post('/finance/collections/'.$record->id.'/payments', $input)->assertForbidden();
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    (new DeliverWorkReminder('finance', 'collections', $record->id, 2, $user->id))->handle(app(WorkReminders::class));
    expect(Payment::count())->toBe(1)->and(LedgerRecord::count())->toBe(1);
    expect(app(Modules::class)->dashboardWidgets($user))->toBe([]);
});
it('refreshes actor access before exact payment retries and suppresses obsolete reminders after owner date or balance changes', function () {
    $user = flUser('collections', true);
    $other = flUser();
    $data = flData($user);
    $record = app(Ledger::class)->create($user, 'collections', $data);
    app(WorkReminders::class)->enqueueDue();
    expect(app(WorkReminders::class)->forUser($user))->toHaveCount(1);
    app(Ledger::class)->update($user, 'collections', $record->id, [...$data, 'next_action_on' => '2026-10-31', 'version' => 1]);
    expect(app(WorkReminders::class)->forUser($user))->toBe([]);
    app(Ledger::class)->update($user, 'collections', $record->id, [...$data, 'owner_id' => $other->id, 'version' => 2]);
    app(WorkReminders::class)->enqueueDue();
    expect(app(WorkReminders::class)->forUser($user))->toBe([])->and(app(WorkReminders::class)->forUser($other))->toHaveCount(1);
    $input = flPayment(3, ['amount' => '1000.10']);
    app(Ledger::class)->payment($other, 'collections', $record->id, $input);
    expect(app(WorkReminders::class)->forUser($other))->toBe([]);
    $other->forceFill(['active' => false])->save();
    expect(fn () => app(Ledger::class)->payment($other, 'collections', $record->id, $input))->toThrow(HttpException::class);
    expect(Payment::count())->toBe(1);
});
it('shares advanced table sorting filtering column preferences and formula-safe export with exact decimal values', function () {
    $user = flUser();
    $data = flData($user, 'collections', ['reference' => "\u{FEFF}=SUM(A1:A2)"]);
    $record = app(Ledger::class)->create($user, 'collections', $data);
    app(Ledger::class)->payment($user, 'collections', $record->id, flPayment());
    $this->actingAs($user)->get('/finance/collections?filters[0][id]=remaining_amount&filters[0][op]=gt&filters[0][value]=600&sorts[0][id]=total&sorts[0][direction]=asc')->assertInertia(fn (Assert $p) => $p->has('table.page.data', 1)->where('table.page.data.0.cells.remaining_amount', '666.77')->where('table.page.data.0.record_link_column', 'reference'));
    $csv = $this->get('/finance/collections/export?filters[0][id]=status&filters[0][op]=eq&filters[0][value]=part-paid')->assertOk()->streamedContent();
    expect($csv)->toContain("'\u{FEFF}=SUM(A1:A2)")->toContain('666.77')->toContain('Part paid');
    $this->post('/preferences', ['table' => 'finance.collections', 'columns' => ['reference', 'total', 'paid_amount'], 'widths' => [], 'pinned' => [], 'density' => 'comfortable', 'wrap' => false, 'views' => []])->assertRedirect();
    $this->get('/finance/collections')->assertInertia(fn (Assert $p) => $p->where('table.preferences.columns', ['reference', 'total', 'paid_amount']));
});
it('keeps one configured currency and blocks financial writes and mixed aggregation on a deployment mismatch', function () {
    $user = flUser();
    $data = flData($user);
    $record = app(Ledger::class)->create($user, 'collections', $data);
    $manifest = Installation::manifest();
    $manifest['currency'] = $record->currency === 'EUR' ? 'USD' : 'EUR';
    file_put_contents(config('core.manifest'), json_encode($manifest));
    $this->actingAs($user)->post('/finance/collections', flData($user))->assertSessionHasErrors('currency');
    $this->patch('/finance/collections/'.$record->id, [...$data, 'version' => 1])->assertSessionHasErrors('currency');
    $this->post('/finance/collections/'.$record->id.'/payments', flPayment())->assertSessionHasErrors('currency');
    $this->get('/finance')->assertInertia(fn (Assert $p) => $p->where('metrics.collections.expected', null)->where('metrics.collections.actual', null)->where('currencyError', Currency::ERROR));
    $this->get('/finance/collections/'.$record->id)->assertOk();
    expect(Payment::count())->toBe(0);
});
it('rejects unsafe supporting links invalid precisions forged relation roles and empty next action dates', function () {
    $user = flUser();
    $data = flData($user);
    $this->actingAs($user)->post('/finance/collections', [...$data, 'document_url' => 'https://user:secret@example.test/file'])->assertSessionHasErrors('document_url');
    $this->post('/finance/collections', [...$data, 'total' => '1.001'])->assertSessionHasErrors('total');
    $this->post('/finance/collections', [...$data, 'total' => '1e3'])->assertSessionHasErrors('total');
    $this->post('/finance/collections', [...$data, 'total' => 0.1])->assertSessionHasErrors('total');
    $this->post('/finance/collections', [...$data, 'total' => '0'])->assertSessionHasErrors('total');
    $this->post('/finance/collections', [...$data, 'next_action_on' => null])->assertSessionHasErrors('next_action');
    $supplier = flData($user, 'expenses');
    $this->post('/finance/collections', [...$data, 'relationship_id' => $supplier['relationship_id']])->assertForbidden();
    expect(LedgerRecord::count())->toBe(0);
});
