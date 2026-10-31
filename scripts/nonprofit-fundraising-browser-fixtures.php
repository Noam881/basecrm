<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Services\IdentityRelationships;
use Awove\Finance\Ledger;
use Awove\Nonprofit\Fundraising\Capability;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.alpha');
$app->make(Kernel::class)->bootstrap();
FictionalCrmFixtures::assertLocalExample('alpha');
$actual = DB::selectOne('select current_database() as name,current_user as role');
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha' || config('mail.default') !== 'smtp' || config('mail.mailers.smtp.host') !== '127.0.0.1' || config('mail.mailers.smtp.port') != 1028) {
    throw new RuntimeException('Only isolated Alpha and local Mailpit are permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose a known fixture operation/project.');
}
if (! Schema::hasTable('nonprofit_constituents')) {
    throw new RuntimeException('Apply controlled additive local migrations first.');
}
$email = 'nonprofit-fundraising-'.$project.'@alpha.example.test';
$contactEmail = 'nonprofit-fundraising-contact-'.$project.'@alpha.example.test';
$u = User::where('email', $email)->first();
if ($u && $u->name !== 'Tamar Cohen') {
    throw new RuntimeException('A protected user occupies the reserved fixture email.');
}
function fundraisingForeign(string $table, $ids, array $allowed): void
{
    $keys = DB::select("SELECT c.conrelid::regclass::text AS table_name,a.attname AS column_name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=c.conkey[1] WHERE c.contype='f' AND c.confrelid=?::regclass", [$table]);
    foreach ($keys as $key) {
        if (! in_array($key->table_name, $allowed, true) && DB::table($key->table_name)->whereIn($key->column_name, $ids)->exists()) {
            throw new RuntimeException('A protected foreign reference prevents fundraising fixture cleanup.');
        }
    }
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $u) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-fundraising-fixtures']);
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        $u = $u ?? User::factory()->create(['name' => 'Tamar Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $u->forceFill(['active' => true, 'display_preferences' => null])->save();
        $u->assignRole('Employee');
        $u->syncPermissions([...array_diff((new Capability)->permissions(), ['nonprofit.fundraising.view.all', 'nonprofit.fundraising.edit.all']), 'identities.classifications.view', 'identities.classifications.manage', 'finance.collections.view.own', 'finance.collections.create', 'finance.collections.edit.own', 'finance.collections.record-payment']);
        $manifest = Installation::manifest();
        $manifest['modules'] = ['nonprofit', 'finance'];
        $manifest['capabilities'] = ['sales' => false, 'nonprofit:programs' => false, 'nonprofit:fundraising' => true, 'nonprofit:projects' => false, 'finance:collections' => true, 'finance:expenses' => false, 'finance:payroll' => false];
        $path = storage_path('app/private/nonprofit-fundraising-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        config(['core.manifest' => $path]);
        if (Contact::where('email', $contactEmail)->exists()) {
            throw new RuntimeException('A reserved identity exists; strict cleanup must run first.');
        }
        $c = Contact::create(['name' => 'Yael Shalev', 'email' => $contactEmail, 'phone' => '+1 202-555-0101', 'search_key' => 'yaelshalev', 'created_by' => $u->id]);
        $customer = app(IdentityRelationships::class)->create($u, ['contact_id' => $c->id, 'role' => 'customer', 'responsible_id' => $u->id]);
        $today = now(Installation::settings()['timezone']);
        $charge = app(Ledger::class)->create($u, 'collections', ['relationship_id' => $customer->id, 'owner_id' => $u->id, 'reference' => 'Existing Finance contribution', 'description' => 'Fictional received contribution recorded separately in Finance.', 'total' => '100.50', 'due_on' => $today->copy()->addDays(14)->toDateString()]);
        $payment = app(Ledger::class)->payment($u, 'collections', $charge->id, ['version' => $charge->version, 'amount' => '25.25', 'paid_on' => $today->toDateString(), 'submission_key' => (string) Str::uuid()]);
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));

        return ['email' => $email, 'owner_id' => $u->id, 'contact_id' => $c->id, 'payment_id' => $payment->id, 'charge_id' => $charge->id, 'today' => $today->toDateString(), 'yesterday' => $today->copy()->subDay()->toDateString(), 'next' => $today->copy()->addDays(14)->toDateString(), 'manifest' => $path];
    }
    if (! $u) {
        return ['cleaned' => 0];
    }
    $contacts = DB::table('crm_contacts')->where(fn ($q) => $q->where('email', $contactEmail)->orWhere('created_by', $u->id))->get();
    foreach ($contacts as $row) {
        if ($row->created_by !== $u->id || $row->email !== $contactEmail || $row->name !== 'Yael Shalev') {
            throw new RuntimeException('A protected identity uses the fixture account.');
        }
    }
    $relations = DB::table('crm_identity_relationships')->where(fn ($q) => $q->whereIn('contact_id', $contacts->pluck('id'))->orWhere('created_by', $u->id))->get();
    foreach ($relations as $row) {
        if (! $contacts->pluck('id')->contains($row->contact_id) || $row->company_id !== null || $row->created_by !== $u->id || $row->responsible_id !== $u->id || ! in_array($row->role, ['customer', 'donor'], true)) {
            throw new RuntimeException('A protected classification prevents cleanup.');
        }
    }
    $contexts = DB::table('nonprofit_constituents')->where(fn ($q) => $q->whereIn('relationship_id', $relations->pluck('id'))->orWhere('created_by', $u->id)->orWhere('owner_id', $u->id))->get();
    foreach ($contexts as $row) {
        if ($row->created_by !== $u->id || $row->owner_id !== $u->id || ! $relations->pluck('id')->contains($row->relationship_id)) {
            throw new RuntimeException('A protected fundraising context prevents cleanup.');
        }
    }
    $pledges = DB::table('nonprofit_pledges')->where(fn ($q) => $q->whereIn('constituent_id', $contexts->pluck('id'))->orWhere('created_by', $u->id))->get();
    foreach ($pledges as $row) {
        if ($row->created_by !== $u->id || ! $contexts->pluck('id')->contains($row->constituent_id) || ! in_array($row->reference, ['Autumn education pledge', 'Library pledge'], true)) {
            throw new RuntimeException('A protected pledge prevents cleanup.');
        }
    }
    $charges = DB::table('finance_records')->where(fn ($q) => $q->whereIn('relationship_id', $relations->pluck('id'))->orWhere('created_by', $u->id)->orWhere('owner_id', $u->id))->get();
    foreach ($charges as $row) {
        if ($row->created_by !== $u->id || $row->owner_id !== $u->id || ! $relations->pluck('id')->contains($row->relationship_id) || $row->reference !== 'Existing Finance contribution' || $row->kind !== 'charge' || $row->total !== '100.50') {
            throw new RuntimeException('A protected financial record prevents cleanup.');
        }
    }
    $payments = DB::table('finance_payments')->where('record_id', '!=', 0)->where(fn ($q) => $q->whereIn('record_id', $charges->pluck('id'))->orWhere('actor_id', $u->id))->get();
    foreach ($payments as $row) {
        if ($row->actor_id !== $u->id || ! $charges->pluck('id')->contains($row->record_id) || $row->amount !== '25.25') {
            throw new RuntimeException('A protected payment prevents cleanup.');
        }
    }
    $links = DB::table('nonprofit_pledge_receipts')->where(fn ($q) => $q->whereIn('pledge_id', $pledges->pluck('id'))->orWhereIn('payment_id', $payments->pluck('id'))->orWhere('actor_id', $u->id))->get();
    foreach ($links as $row) {
        if ($row->actor_id !== $u->id || ! $pledges->pluck('id')->contains($row->pledge_id) || ! $payments->pluck('id')->contains($row->payment_id) || ! $charges->pluck('id')->contains($row->finance_record_id)) {
            throw new RuntimeException('A protected receipt relationship prevents cleanup.');
        }
    }
    if (Schema::hasTable('nonprofit_project_payments') && DB::table('nonprofit_project_payments')->whereIn('payment_id', $payments->pluck('id'))->exists()) {
        throw new RuntimeException('A protected project references this actual payment.');
    }
    $history = DB::table('nonprofit_fundraising_history')->where('constituent_id', '!=', 0)->where(fn ($q) => $q->whereIn('constituent_id', $contexts->pluck('id'))->orWhere('actor_id', $u->id))->get();
    $financeHistory = DB::table('finance_record_history')->whereIn('record_id', $charges->pluck('id'))->get();
    $relationHistory = DB::table('crm_identity_relationship_history')->whereIn('relationship_id', $relations->pluck('id'))->get();
    $identityHistory = DB::table('crm_identity_activity')->whereIn('contact_id', $contacts->pluck('id'))->get();
    foreach ([$history, $financeHistory, $relationHistory, $identityHistory] as $rows) {
        foreach ($rows as $row) {
            if ($row->actor_id !== $u->id) {
                throw new RuntimeException('A protected historical actor prevents cleanup.');
            }
        }
    }foreach ($history as $row) {
        if (! $contexts->pluck('id')->contains($row->constituent_id) || ($row->pledge_id !== null && ! $pledges->pluck('id')->contains($row->pledge_id))) {
            throw new RuntimeException('A protected history parent prevents cleanup.');
        }
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'nonprofit')->where('source', 'fundraising-actions')->whereIn('record_id', $contexts->pluck('id'))->get();
    if ($notices->contains(fn ($row) => $row->user_id !== $u->id)) {
        throw new RuntimeException('A protected reminder prevents cleanup.');
    }
    fundraisingForeign('crm_contacts', $contacts->pluck('id'), ['crm_identity_relationships', 'crm_identity_activity']);
    fundraisingForeign('crm_identity_relationships', $relations->pluck('id'), ['crm_identity_relationship_history', 'nonprofit_constituents', 'finance_records']);
    fundraisingForeign('nonprofit_constituents', $contexts->pluck('id'), ['nonprofit_pledges', 'nonprofit_fundraising_history']);
    fundraisingForeign('nonprofit_pledges', $pledges->pluck('id'), ['nonprofit_pledge_receipts', 'nonprofit_fundraising_history']);
    fundraisingForeign('finance_records', $charges->pluck('id'), ['finance_payments', 'finance_record_history']);
    fundraisingForeign('finance_payments', $payments->pluck('id'), []);
    $groups = ['nonprofit_fundraising_history' => $history, 'nonprofit_pledge_receipts' => $links, 'nonprofit_pledges' => $pledges, 'nonprofit_constituents' => $contexts, 'crm_work_reminders' => $notices, 'finance_record_history' => $financeHistory, 'finance_payments' => $payments, 'finance_records' => $charges, 'crm_identity_relationship_history' => $relationHistory, 'crm_identity_relationships' => $relations, 'crm_identity_activity' => $identityHistory, 'crm_contacts' => $contacts];
    if (collect($groups)->contains(fn ($rows) => $rows->isNotEmpty())) {
        $dir = storage_path('app/private/nonprofit-fundraising-fixture-recovery');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }$path = $dir.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode(array_map(fn ($rows) => $rows->all(), $groups), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
    }
    foreach ($groups as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }

    return ['cleaned' => $contexts->count() + $pledges->count() + $contacts->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
