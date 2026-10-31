<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Jobs\DeliverWorkReminder;
use Awove\Core\Models\Contact;
use Awove\Core\Models\WorkReminder;
use Awove\Core\Modules;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Services\WorkReminders;
use Awove\Finance\Ledger;
use Awove\Nonprofit\Fundraising\Access;
use Awove\Nonprofit\Fundraising\Models\Constituent;
use Awove\Nonprofit\Fundraising\Models\History;
use Awove\Nonprofit\Fundraising\Models\Pledge;
use Awove\Nonprofit\Fundraising\Models\ReceiptLink;
use Awove\Nonprofit\Fundraising\Receipts;
use Awove\Nonprofit\Fundraising\Records;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    $this->withoutVite();
    $this->npoFile = tempnam(sys_get_temp_dir(), 'npo-fundraising-');
    $this->npoSettings = Installation::manifest();
    $this->npoSettings['modules'] = ['nonprofit'];
    $this->npoSettings['capabilities'] = ['sales' => false, 'nonprofit:programs' => false, 'nonprofit:fundraising' => true, 'nonprofit:projects' => false];
    if (isset(app(Modules::class)->installed()['finance'])) {
        $this->npoSettings['capabilities'] = [...$this->npoSettings['capabilities'], 'finance:collections' => false, 'finance:expenses' => false, 'finance:payroll' => false];
    }
    $this->npoSettings['currency'] = 'USD';
    $this->npoSettings['timezone'] = 'Asia/Jerusalem';
    file_put_contents($this->npoFile, json_encode($this->npoSettings));
    config(['core.manifest' => $this->npoFile]);
    $this->artisan('crm:install')->assertSuccessful();
    $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00+02:00'));
});
afterEach(function () {
    unlink($this->npoFile);
});
function fundraisingStaff(bool $all = false, bool $money = true, bool $receipts = true): User
{
    $u = User::factory()->create(['name' => 'Tamar Cohen']);
    $u->assignRole('Employee');
    $permissions = ['nonprofit.fundraising.view.own', 'nonprofit.fundraising.create', 'nonprofit.fundraising.edit.own', 'nonprofit.fundraising.archive', 'nonprofit.fundraising.export', 'identities.classifications.view', 'identities.classifications.manage'];
    if ($all) {
        $permissions = [...$permissions, 'nonprofit.fundraising.view.all', 'nonprofit.fundraising.edit.all', 'nonprofit.fundraising.assign'];
    }if ($money) {
        $permissions = [...$permissions, 'nonprofit.fundraising.pledges.view', 'nonprofit.fundraising.pledges.manage'];
    }if ($receipts) {
        $permissions = [...$permissions, 'nonprofit.fundraising.receipts.view', 'nonprofit.fundraising.receipts.manage'];
    }$u->givePermissionTo($permissions);

    return $u;
}
function fundraisingRelationship(User $u, string $role = 'donor')
{
    $c = Contact::create(['name' => 'Yael Shalev', 'email' => (string) Str::uuid().'@example.test', 'phone' => '+1 202-555-0100', 'search_key' => 'yael shalev', 'created_by' => $u->id]);

    return app(IdentityRelationships::class)->create($u, ['contact_id' => $c->id, 'role' => $role, 'responsible_id' => $u->id]);
}
function fundraisingContext(User $u, array $changes = []): Constituent
{
    $r = fundraisingRelationship($u);

    return app(Records::class)->save($u, [...['relationship_id' => $r->id, 'owner_id' => $u->id, 'next_action' => 'Call about the education program', 'next_action_on' => '2026-10-31', 'notes' => 'Fictional supporter.'], ...$changes]);
}
function fundraisingPledge(User $u, Constituent $c, array $changes = []): Pledge
{
    return app(Records::class)->pledge($u, $c->id, [...['constituent_version' => $c->fresh()->version, 'reference' => 'Autumn education pledge', 'amount' => '100.50', 'expected_on' => '2026-11-15', 'restrictions' => 'Books and learning materials only.'], ...$changes]);
}
function fundraisingFinance($test, User $u, Constituent $c, string $amount = '25.25'): array
{
    $test->npoSettings['modules'] = ['nonprofit', 'finance'];
    $test->npoSettings['capabilities']['finance:collections'] = true;
    file_put_contents($test->npoFile, json_encode($test->npoSettings));
    $test->artisan('crm:install')->assertSuccessful();
    $u->givePermissionTo(['finance.collections.view.own', 'finance.collections.create', 'finance.collections.edit.own', 'finance.collections.record-payment']);
    $donor = Access::relationships($u)->findOrFail($c->getAttribute('relationship_id'));
    $customer = app(IdentityRelationships::class)->query($u, 'customer')->where('contact_id', $donor->contact_id)->first() ?? app(IdentityRelationships::class)->create($u, ['contact_id' => $donor->contact_id, 'role' => 'customer', 'responsible_id' => $u->id]);
    $charge = app(Ledger::class)->create($u, 'collections', ['relationship_id' => $customer->id, 'owner_id' => $u->id, 'reference' => 'Existing Finance contribution', 'description' => 'Recorded separately in Finance', 'total' => '100.50', 'due_on' => '2026-11-15']);
    $payment = app(Ledger::class)->payment($u, 'collections', $charge->id, ['version' => $charge->version, 'amount' => $amount, 'paid_on' => '2026-10-30', 'submission_key' => (string) Str::uuid()]);

    return [$charge->fresh(), $payment];
}
function fundraisingReceiptData(Constituent $c, Pledge $p, $charge, $payment): array
{
    return ['constituent_version' => $c->fresh()->version, 'pledge_version' => $p->fresh()->version, 'finance_record_id' => $charge->id, 'finance_version' => $charge->fresh()->version, 'payment_id' => $payment->id];
}

it('persists fundraising contexts and exact restricted pledges without other business modules or copied contacts', function () {
    $u = fundraisingStaff();
    $r = fundraisingRelationship($u);
    $count = Contact::count();
    $this->actingAs($u)->post('/nonprofit/fundraising/constituents', ['relationship_id' => $r->id, 'next_action' => 'Call Yael', 'next_action_on' => '2026-10-31'])->assertRedirect();
    $c = Constituent::sole();
    expect(Contact::count())->toBe($count);
    $this->get('/nonprofit/fundraising/constituents/'.$c->id)->assertInertia(fn (Assert $p) => $p->where('record.relationship.name', 'Yael Shalev')->where('record.owner_id', $u->id));
    $p = fundraisingPledge($u, $c);
    $this->get('/nonprofit/fundraising/pledges/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('record.amount', '100.50')->where('record.currency', 'USD')->where('record.restrictions', 'Books and learning materials only.')->where('actuals.available', false)->missing('actuals.amount'));
    foreach (['sales', 'hr', 'operations', 'finance', 'assets'] as $m) {
        expect(app(Modules::class)->enabled($m))->toBeFalse();
    }
});
it('enforces own and all scopes with independent underlying identity visibility', function () {
    $owner = fundraisingStaff();
    $viewer = fundraisingStaff(true);
    $c = fundraisingContext($owner);
    $this->actingAs($viewer)->get('/nonprofit/fundraising/constituents/'.$c->id)->assertNotFound();
    $viewer->givePermissionTo('identities.view.all');
    $this->get('/nonprofit/fundraising/constituents/'.$c->id)->assertOk();
    $viewer->revokePermissionTo('nonprofit.fundraising.view.all');
    $this->get('/nonprofit/fundraising/constituents/'.$c->id)->assertNotFound();
});
it('rejects unauthorized assignment inactive assignees and stale context edits', function () {
    $u = fundraisingStaff();
    $other = fundraisingStaff();
    $c = fundraisingContext($u);
    $this->actingAs($u)->patchJson('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 1, 'owner_id' => $other->id])->assertForbidden();
    $this->patchJson('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 2, 'notes' => 'Stale'])->assertConflict();
    $u->givePermissionTo('nonprofit.fundraising.assign');
    $other->forceFill(['active' => false])->save();
    $this->patchJson('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 1, 'owner_id' => $other->id])->assertUnprocessable();
});
it('retains shared classifications and rejects duplicate contexts or identity replacement', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $r = fundraisingRelationship($u, 'partner');
    $this->actingAs($u)->postJson('/nonprofit/fundraising/constituents', ['relationship_id' => $c->getAttribute('relationship_id')])->assertUnprocessable();
    $this->patchJson('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 1, 'relationship_id' => $r->id])->assertUnprocessable();
    expect($c->fresh()->getAttribute('relationship_id'))->not->toBe($r->id);
});
it('requires paired manual action and date and retains inactive owners on unrelated edits', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $this->actingAs($u)->patchJson('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 1, 'next_action_on' => null])->assertUnprocessable();
    $editor = fundraisingStaff(true);
    $editor->givePermissionTo('identities.view.all');
    $u->forceFill(['active' => false])->save();
    $this->actingAs($editor)->patch('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 1, 'notes' => 'Retained responsibility'])->assertRedirect();
    expect($c->fresh()->owner_id)->toBe($u->id);
});
it('protects monetary fields queries exports and pledge history independently', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    $u->revokePermissionTo(['nonprofit.fundraising.pledges.view', 'nonprofit.fundraising.pledges.manage']);
    $this->actingAs($u)->get('/nonprofit/fundraising/constituents/'.$c->id)->assertInertia(fn (Assert $page) => $page->has('pledges', 0)->where('history.0.event', 'constituent.created'));
    $this->get('/nonprofit/fundraising/pledges/'.$p->id)->assertNotFound();
    $this->getJson('/nonprofit/fundraising?tab=pledges')->assertForbidden();
    $this->getJson('/nonprofit/fundraising/export?tab=pledges')->assertForbidden();
    $this->postJson('/nonprofit/fundraising/constituents/'.$c->id.'/pledges', ['constituent_version' => $c->fresh()->version, 'amount' => '1.00', 'expected_on' => '2026-11-01'])->assertForbidden();
});
it('validates exact amounts dates restrictions and versions while preserving pledge currency', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    $this->actingAs($u)->patchJson('/nonprofit/fundraising/pledges/'.$p->id, ['constituent_version' => $c->fresh()->version, 'version' => 1, 'amount' => 1.234])->assertUnprocessable();
    $this->patch('/nonprofit/fundraising/pledges/'.$p->id, ['constituent_version' => $c->fresh()->version, 'version' => 1, 'amount' => '150.75'])->assertRedirect();
    expect($p->fresh()->getAttribute('amount'))->toBe('150.75')->and($p->fresh()->getAttribute('currency'))->toBe('USD');
    $this->patchJson('/nonprofit/fundraising/pledges/'.$p->id, ['constituent_version' => $c->fresh()->version, 'version' => 1, 'expected_on' => '2026-11-16'])->assertConflict();
    $this->npoSettings['currency'] = 'EUR';
    file_put_contents($this->npoFile, json_encode($this->npoSettings));
    $this->patchJson('/nonprofit/fundraising/pledges/'.$p->id, ['constituent_version' => $c->fresh()->version, 'version' => $p->fresh()->version, 'amount' => '1.00'])->assertUnprocessable();
    expect($p->fresh()->getAttribute('currency'))->toBe('USD');
});
it('links actual payments separately from expected charges and pledges without any Finance writes', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    [$charge,$payment] = fundraisingFinance($this, $u, $c);
    $before = DB::table('finance_payments')->get()->toArray();
    $this->actingAs($u)->post('/nonprofit/fundraising/pledges/'.$p->id.'/receipts', fundraisingReceiptData($c, $p, $charge, $payment))->assertRedirect();
    $this->get('/nonprofit/fundraising/pledges/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('actuals.available', true)->where('actuals.amount', '25.25')->where('record.amount', '100.50'));
    expect(DB::table('finance_payments')->get()->toArray())->toEqual($before)->and(DB::table('finance_records')->count())->toBe(1);
});
it('prevents duplicate active receipt totals stale link retries and wrong donor identity', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    [$charge,$payment] = fundraisingFinance($this, $u, $c);
    $data = fundraisingReceiptData($c, $p, $charge, $payment);
    app(Receipts::class)->link($u, $p->id, $data);
    $this->actingAs($u)->postJson('/nonprofit/fundraising/pledges/'.$p->id.'/receipts', $data)->assertConflict();
    $other = fundraisingPledge($u, $c);
    $this->postJson('/nonprofit/fundraising/pledges/'.$other->id.'/receipts', fundraisingReceiptData($c, $other, $charge, $payment))->assertUnprocessable();
    $wrong = fundraisingContext($u);
    $wrongP = fundraisingPledge($u, $wrong);
    $this->postJson('/nonprofit/fundraising/pledges/'.$wrongP->id.'/receipts', fundraisingReceiptData($wrong, $wrongP, $charge, $payment))->assertForbidden();
    expect(ReceiptLink::count())->toBe(1);
});
it('retains archived receipt links and original payments while allowing explicit corrected linking', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    [$charge,$payment] = fundraisingFinance($this, $u, $c);
    $link = app(Receipts::class)->link($u, $p->id, fundraisingReceiptData($c, $p, $charge, $payment));
    $this->actingAs($u)->post('/nonprofit/fundraising/receipts/'.$link->id.'/archive', ['constituent_version' => $c->fresh()->version, 'pledge_version' => $p->fresh()->version, 'version' => 1])->assertRedirect();
    expect($link->fresh()->archived_at)->not->toBeNull()->and(DB::table('finance_payments')->count())->toBe(1);
    $replacement = app(Receipts::class)->link($u, $p->id, fundraisingReceiptData($c, $p, $charge, $payment));
    expect($replacement->id)->not->toBe($link->id)->and(ReceiptLink::count())->toBe(2);
});
it('redacts actual receipts after counterpart access loss without claiming zero or erasing references', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    [$charge,$payment] = fundraisingFinance($this, $u, $c);
    app(Receipts::class)->link($u, $p->id, fundraisingReceiptData($c, $p, $charge, $payment));
    $u->revokePermissionTo('finance.collections.view.own');
    $response = $this->actingAs($u)->get('/nonprofit/fundraising/pledges/'.$p->id)->assertInertia(fn (Assert $page) => $page->where('actuals.available', false)->missing('actuals.amount')->has('receiptOptions', 0));
    expect(ReceiptLink::count())->toBe(1);
    $u->revokePermissionTo('nonprofit.fundraising.receipts.view');
    $this->get('/nonprofit/fundraising/pledges/'.$p->id)->assertInertia(fn (Assert $page) => $page->missing('actuals')->missing('receiptOptions'));
});
it('uses receipt payment dates separately from pledge expected dates in filtered reports', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    [$charge,$payment] = fundraisingFinance($this, $u, $c);
    app(Receipts::class)->link($u, $p->id, fundraisingReceiptData($c, $p, $charge, $payment));
    $this->actingAs($u)->get('/nonprofit/fundraising?from=2026-10-01&to=2026-10-31')->assertInertia(fn (Assert $page) => $page->where('report.pledged_amount', '0.00')->where('report.received_amount', '25.25'));
    $this->get('/nonprofit/fundraising?from=2026-11-01&to=2026-11-30')->assertInertia(fn (Assert $page) => $page->where('report.pledged_amount', '100.50')->where('report.received_amount', '0.00'));
});
it('exports identical scoped filters safely without actual private Finance references', function () {
    $u = fundraisingStaff();
    $other = fundraisingStaff();
    $c = fundraisingContext($u, ['notes' => '=Private note']);
    fundraisingContext($other, ['notes' => 'Foreign context']);
    $p = fundraisingPledge($u, $c, ['reference' => '=SUM(A1)', 'restrictions' => '=Private restriction']);
    $this->actingAs($u)->get('/nonprofit/fundraising/export')->assertOk();
    $csv = $this->get('/nonprofit/fundraising/export?tab=pledges&q=SUM')->assertOk()->streamedContent();
    expect($csv)->toContain("'=SUM(A1)", "'=Private restriction", '100.50')->not->toContain('Foreign context', 'payment_id', 'finance_record_id');
});
it('suppresses stale follow-ups after reschedule clear archive permission loss and capability disablement', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $service = app(WorkReminders::class);
    $service->enqueueDue();
    $notice = WorkReminder::where('module', 'nonprofit')->where('source', 'fundraising-actions')->sole();
    expect($service->forUser($u))->toHaveCount(1);
    app(Records::class)->save($u, ['version' => $c->version, 'next_action_on' => '2026-11-15'], $c->id);
    (new DeliverWorkReminder($notice->module, $notice->source, $notice->record_id, $notice->record_version, $notice->user_id))->handle();
    expect($service->forUser($u))->toBe([]);
    $this->npoSettings['capabilities']['nonprofit:fundraising'] = false;
    file_put_contents($this->npoFile, json_encode($this->npoSettings));
    $this->actingAs($u)->get('/nonprofit/fundraising')->assertNotFound();
    expect(Constituent::count())->toBe(1);
    $this->npoSettings['capabilities']['nonprofit:fundraising'] = true;
    file_put_contents($this->npoFile, json_encode($this->npoSettings));
    $this->get('/nonprofit/fundraising/constituents/'.$c->id)->assertOk();
    $u->revokePermissionTo('nonprofit.fundraising.view.own');
    expect(Access::query($u)->count())->toBe(0);
});
it('contextually classifies shared donors partners and prospects without implicit identity permissions', function () {
    $u = fundraisingStaff();
    $contact = Contact::create(['name' => 'Noam Erez', 'email' => 'noam.context@example.test', 'search_key' => 'noam erez', 'created_by' => $u->id]);
    $this->actingAs($u)->post('/nonprofit/fundraising/classifications', ['contact_id' => $contact->id, 'role' => 'partner', 'responsible_id' => $u->id])->assertRedirect();
    $this->postJson('/nonprofit/fundraising/classifications', ['contact_id' => $contact->id, 'role' => 'supplier'])->assertUnprocessable();
    $u->revokePermissionTo('identities.classifications.manage');
    $this->postJson('/nonprofit/fundraising/classifications', ['contact_id' => $contact->id, 'role' => 'prospect'])->assertForbidden();
});
it('archives contexts pledges and retains append-only history without mutating accounting', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    $this->actingAs($u)->post('/nonprofit/fundraising/pledges/'.$p->id.'/archive', ['constituent_version' => $c->fresh()->version, 'version' => $p->version])->assertRedirect();
    $this->post('/nonprofit/fundraising/constituents/'.$c->id.'/archive', ['version' => $c->fresh()->version])->assertRedirect();
    $this->patchJson('/nonprofit/fundraising/constituents/'.$c->id, ['version' => $c->fresh()->version, 'notes' => 'Cannot edit'])->assertForbidden();
    expect(Pledge::count())->toBe(1)->and(History::count())->toBe(4);
    expect(fn () => History::first()->update(['event' => 'changed']))->toThrow(LogicException::class);
});

it('reauthorizes and reloads arbitrary constituent models at the optional receipt adapter boundary', function () {
    $u = fundraisingStaff();
    $other = fundraisingStaff();
    $foreign = fundraisingContext($other);
    $u->givePermissionTo(['identities.view.all', 'finance.collections.view.all']);
    [$charge,$payment] = fundraisingFinance($this, $u, $foreign);
    expect(app(Receipts::class)->reference($u, $foreign, $charge->id, $payment->id))->toBeNull();
    $own = fundraisingContext($u);
    $own->setAttribute('relationship_id', $foreign->getAttribute('relationship_id'));
    expect(app(Receipts::class)->reference($u, $own, $charge->id, $payment->id))->toBeNull();
});

it('requires new fundraising owners to already see the same shared donor identity without granting it', function () {
    $u = fundraisingStaff();
    $u->givePermissionTo('nonprofit.fundraising.assign');
    $other = fundraisingStaff();
    $c = fundraisingContext($u);
    $this->actingAs($u)->patchJson('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 1, 'owner_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('owner_id');
    $this->get('/nonprofit/fundraising/constituents/'.$c->id)->assertInertia(fn (Assert $page) => $page->where('owners', fn ($owners) => ! collect($owners)->pluck('id')->contains($other->id)));
    expect(Access::query($other)->count())->toBe(0)->and($other->fresh()->can('identities.view.all'))->toBeFalse();
    $other->givePermissionTo('identities.view.all');
    $this->patch('/nonprofit/fundraising/constituents/'.$c->id, ['version' => 1, 'owner_id' => $other->id])->assertRedirect();
    expect(Access::query($other)->whereKey($c->id)->exists())->toBeTrue();
});

it('retains actual receipt totals after archiving expectations while excluding them from active pledge totals', function () {
    $u = fundraisingStaff();
    $c = fundraisingContext($u);
    $p = fundraisingPledge($u, $c);
    [$charge,$payment] = fundraisingFinance($this, $u, $c);
    app(Receipts::class)->link($u, $p->id, fundraisingReceiptData($c, $p, $charge, $payment));
    app(Records::class)->archivePledge($u, $p->id, ['constituent_version' => $c->fresh()->version, 'version' => $p->fresh()->version]);
    app(Records::class)->archive($u, $c->id, ['version' => $c->fresh()->version]);
    $this->actingAs($u)->get('/nonprofit/fundraising?from=2026-10-01&to=2026-11-30')->assertInertia(fn (Assert $page) => $page->where('report.constituents', 0)->where('report.pledged_amount', '0.00')->where('report.received_amount', '25.25'));
    expect(DB::table('finance_payments')->count())->toBe(1)->and(ReceiptLink::whereNull('archived_at')->count())->toBe(1);
});
