<?php

use App\Models\User;
use Awove\Assets\Models\Asset;
use Awove\Assets\Records;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Services\IdentityRelationships;
use Awove\Finance\Ledger;
use Awove\Finance\Models\LedgerRecord;
use Awove\Finance\Models\Payment;
use Awove\Nonprofit\Projects\Capability;
use Awove\Operations\Models\SupplierOrder;
use Awove\Operations\Orders;
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
if (! in_array($mode, ['setup', 'cleanup', 'connect', 'disconnect'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose an explicit fixture operation and known browser project.');
}if (! Schema::hasTable('nonprofit_projects')) {
    throw new RuntimeException('Apply the controlled additive local migrations first.');
}
$email = 'nonprofit-projects-'.$project.'@alpha.example.test';
$contactEmail = 'nonprofit-projects-contact-'.$project.'@alpha.example.test';
$companyEmail = 'nonprofit-projects-company-'.$project.'@alpha.example.test';
$u = User::where('email', $email)->first();
if ($u && $u->name !== 'Shira Cohen') {
    throw new RuntimeException('A protected user occupies this reserved email.');
}$path = storage_path('app/private/nonprofit-projects-browser-manifest.json');
if (is_file($path)) {
    config(['core.manifest' => $path]);
}
function projectFixtureForeign(string $table, $ids, array $allowed): void
{
    $keys = DB::select("SELECT c.conrelid::regclass::text AS table_name,a.attname AS column_name FROM pg_constraint c JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=c.conkey[1] WHERE c.contype='f' AND c.confrelid=?::regclass", [$table]);
    foreach ($keys as $key) {
        if (! in_array($key->table_name, $allowed, true) && DB::table($key->table_name)->whereIn($key->column_name, $ids)->exists()) {
            throw new RuntimeException('A protected foreign relationship references this reserved fixture.');
        }
    }
}
function projectFixtureManifest(string $path, bool $connected): void
{
    $manifest = Installation::manifest();
    $manifest['modules'] = $connected ? ['nonprofit', 'finance', 'operations', 'assets'] : ['nonprofit'];
    $manifest['capabilities'] = ['sales' => false, 'nonprofit:projects' => true, 'nonprofit:programs' => false, 'nonprofit:fundraising' => false, 'finance:collections' => $connected, 'finance:expenses' => $connected, 'finance:payroll' => false];
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    chmod($path, 0600);
    config(['core.manifest' => $path]);
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $companyEmail, $u, $path) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-projects-fixtures']);
    $today = now(Installation::settings()['timezone']);
    if ($mode === 'setup') {
        projectFixtureManifest($path, false);
        Artisan::call('crm:install');
        $u = $u ?? User::factory()->create(['name' => 'Shira Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $u->forceFill(['active' => true, 'display_preferences' => null])->save();
        $u->assignRole('Employee');
        $u->syncPermissions(array_values(array_diff((new Capability)->permissions(), ['nonprofit.projects.view.all', 'nonprofit.projects.edit.all', 'nonprofit.projects.assign'])));
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));

        return ['email' => $email, 'owner_id' => $u->id, 'currency' => Installation::settings()['currency'], 'today' => $today->toDateString(), 'yesterday' => $today->copy()->subDay()->toDateString(), 'next' => $today->copy()->addDays(14)->toDateString(), 'manifest' => $path];
    }
    if ($mode === 'disconnect') {
        if (! $u) {
            throw new RuntimeException('Set up reserved fixture first.');
        }projectFixtureManifest($path, false);

        return ['connected' => false];
    }
    if ($mode === 'connect') {
        if (! $u) {
            throw new RuntimeException('Set up reserved fixture first.');
        }projectFixtureManifest($path, true);
        $u->givePermissionTo(['identities.classifications.view', 'identities.classifications.manage', 'assets.view.own', 'assets.create', 'assets.edit.own', 'operations.view.own', 'operations.create', 'operations.edit.own', ...array_merge(...array_map(fn ($cap) => array_map(fn ($a) => 'finance.'.$cap.'.'.$a, ['view.own', 'create', 'edit.own', 'record-payment']), ['collections', 'expenses']))]);
        $asset = Asset::where('created_by', $u->id)->where('code', 'PLAN-101')->first();
        $order = SupplierOrder::where('created_by', $u->id)->where('reference', 'PLAN-ORDER-101')->first();
        $charges = LedgerRecord::where('created_by', $u->id)->get();
        if (! $asset && ! $order && $charges->isEmpty()) {
            if (Contact::where('email', $contactEmail)->exists() || Company::where('email', $companyEmail)->exists()) {
                throw new RuntimeException('Reserved identities exist; strict cleanup must run first.');
            }$contact = Contact::create(['name' => 'Noam Levi', 'email' => $contactEmail, 'phone' => '+1 202-555-0101', 'created_by' => $u->id, 'search_key' => 'noamlevi']);
            $company = Company::create(['name' => 'Carmel Supply', 'email' => $companyEmail, 'phone' => '+1 202-555-0102', 'created_by' => $u->id, 'search_key' => 'carmelsupply']);
            $customer = app(IdentityRelationships::class)->create($u, ['contact_id' => $contact->id, 'role' => 'customer', 'responsible_id' => $u->id]);
            $supplier = app(IdentityRelationships::class)->create($u, ['company_id' => $company->id, 'role' => 'supplier', 'responsible_id' => $u->id]);
            $asset = app(Records::class)->save($u, ['code' => 'PLAN-101', 'name' => 'Community projector', 'type' => 'Equipment', 'location' => 'Community room', 'owner_id' => $u->id, 'status' => 'available']);
            $order = app(Orders::class)->create($u, ['reference' => 'PLAN-ORDER-101', 'description' => 'Learning materials', 'supplier_relationship_id' => $supplier->id, 'owner_id' => $u->id, 'quantity' => '2.000', 'agreed_total' => '10.00', 'ordered_on' => $today->toDateString(), 'status' => 'ordered']);
            foreach ([['collections', $customer, 'Project receipt', '100.10'], ['expenses', $supplier, 'Project supplies', '40.02']] as [$cap,$rel,$ref,$amount]) {
                $ledger = app(Ledger::class);
                $r = $ledger->create($u, $cap, ['reference' => $ref, 'description' => 'Fictional project transaction.', 'relationship_id' => $rel->id, 'owner_id' => $u->id, 'category' => $cap === 'expenses' ? 'Supplies' : null, 'total' => '1000.00', 'due_on' => $today->toDateString()]);
                $ledger->payment($u, $cap, $r->id, ['version' => $r->version, 'amount' => $amount, 'paid_on' => $today->toDateString(), 'submission_key' => (string) Str::uuid()]);
            }
        } elseif (! $asset || ! $order || $charges->count() !== 2) {
            throw new RuntimeException('Partial source fixtures require strict cleanup before reuse.');
        }
        $rows = LedgerRecord::where('created_by', $u->id)->get();

        return ['asset_id' => $asset->id, 'order_id' => $order->id, 'incoming_id' => Payment::where('record_id', $rows->where('kind', 'charge')->sole()->id)->sole()->id, 'outgoing_id' => Payment::where('record_id', $rows->where('kind', 'expense')->sole()->id)->sole()->id];
    }
    if (! $u) {
        return ['cleaned' => 0];
    }
    $projects = DB::table('nonprofit_projects')->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('owner_id', $u->id))->get();
    foreach ($projects as $r) {
        if ($r->created_by !== $u->id || $r->owner_id !== $u->id || ! in_array($r->name, ['Community learning plan', 'Updated community plan'], true)) {
            throw new RuntimeException('A protected project uses this fixture.');
        }
    }$ids = $projects->pluck('id');
    $work = DB::table('crm_work_items')->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('owner_id', $u->id))->get();
    foreach ($work as $r) {
        if ($r->created_by !== $u->id || $r->owner_id !== $u->id || $r->module !== 'nonprofit' || ! in_array($r->kind, ['project-tasks', 'project-milestones'], true) || ! in_array($r->title, ['Prepare learning materials', 'Updated learning task', 'Publish annual plan', 'Send learning outline'], true)) {
            throw new RuntimeException('A protected work item uses this fixture.');
        }
    }$workIds = $work->pluck('id');
    $workLinks = DB::table('nonprofit_project_work')->where(fn ($q) => $q->whereIn('project_id', $ids)->orWhereIn('work_item_id', $workIds))->get();
    foreach ($workLinks as $r) {
        if (! $ids->contains($r->project_id) || ! $workIds->contains($r->work_item_id) || $work->firstWhere('id', $r->work_item_id)->kind !== $r->kind) {
            throw new RuntimeException('A protected work association prevents cleanup.');
        }
    }
    $meetings = DB::table('nonprofit_project_meetings')->where(fn ($q) => $q->whereIn('project_id', $ids)->orWhere('created_by', $u->id))->get();
    foreach ($meetings as $r) {
        if (! $ids->contains($r->project_id) || $r->created_by !== $u->id || ($r->follow_up_work_item_id !== null && ! $workIds->contains($r->follow_up_work_item_id))) {
            throw new RuntimeException('A protected meeting prevents cleanup.');
        }
    }
    $budgets = DB::table('nonprofit_project_budgets')->where(fn ($q) => $q->whereIn('project_id', $ids)->orWhere('actor_id', $u->id))->get();
    foreach ($budgets as $r) {
        if (! $ids->contains($r->project_id) || $r->actor_id !== $u->id || ! in_array($r->amount, ['200.00', '300.00'], true)) {
            throw new RuntimeException('A protected budget prevents cleanup.');
        }
    }
    $documents = DB::table('nonprofit_project_documents')->where(fn ($q) => $q->whereIn('project_id', $ids)->orWhere('actor_id', $u->id))->get();
    foreach ($documents as $r) {
        if (! $ids->contains($r->project_id) || $r->actor_id !== $u->id || $r->name !== 'Annual plan document' || $r->url !== 'https://documents.example.test/annual-plan') {
            throw new RuntimeException('A protected document prevents cleanup.');
        }
    }
    $contacts = DB::table('crm_contacts')->where(fn ($q) => $q->where('email', $contactEmail)->orWhere('created_by', $u->id))->get();
    $companies = DB::table('crm_companies')->where(fn ($q) => $q->where('email', $companyEmail)->orWhere('created_by', $u->id))->get();
    foreach ([...$contacts, ...$companies] as $r) {
        if ($r->created_by !== $u->id || ! in_array($r->email, [$contactEmail, $companyEmail], true) || ! in_array($r->name, ['Noam Levi', 'Carmel Supply'], true)) {
            throw new RuntimeException('A protected identity prevents cleanup.');
        }
    }
    $relations = DB::table('crm_identity_relationships')->where(fn ($q) => $q->whereIn('contact_id', $contacts->pluck('id'))->orWhereIn('company_id', $companies->pluck('id'))->orWhere('created_by', $u->id))->get();
    foreach ($relations as $r) {
        if ($r->created_by !== $u->id || $r->responsible_id !== $u->id || $r->main_contact_id !== null || ! in_array($r->role, ['customer', 'supplier'], true) || (! $contacts->pluck('id')->contains($r->contact_id) && ! $companies->pluck('id')->contains($r->company_id))) {
            throw new RuntimeException('A protected classification prevents cleanup.');
        }
    }
    $assets = DB::table('assets_register')->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('owner_id', $u->id))->get();
    foreach ($assets as $r) {
        if ($r->created_by !== $u->id || $r->owner_id !== $u->id || $r->code !== 'PLAN-101' || $r->name !== 'Community projector') {
            throw new RuntimeException('A protected source asset prevents cleanup.');
        }
    }
    $orders = DB::table('operations_orders')->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('owner_id', $u->id)->orWhereIn('supplier_relationship_id', $relations->pluck('id')))->get();
    foreach ($orders as $r) {
        if ($r->created_by !== $u->id || $r->owner_id !== $u->id || $r->reference !== 'PLAN-ORDER-101' || ! $relations->pluck('id')->contains($r->supplier_relationship_id)) {
            throw new RuntimeException('A protected purchase prevents cleanup.');
        }
    }
    $finance = DB::table('finance_records')->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('owner_id', $u->id)->orWhereIn('relationship_id', $relations->pluck('id')))->get();
    foreach ($finance as $r) {
        if ($r->created_by !== $u->id || $r->owner_id !== $u->id || ! $relations->pluck('id')->contains($r->relationship_id) || ! in_array($r->reference, ['Project receipt', 'Project supplies'], true) || $r->total !== '1000.00') {
            throw new RuntimeException('A protected Finance record prevents cleanup.');
        }
    }$payments = DB::table('finance_payments')->where(fn ($q) => $q->whereIn('record_id', $finance->pluck('id'))->orWhere('actor_id', $u->id))->get();
    foreach ($payments as $r) {
        if ($r->actor_id !== $u->id || ! $finance->pluck('id')->contains($r->record_id) || ! in_array($r->amount, ['100.10', '40.02'], true)) {
            throw new RuntimeException('A protected payment prevents cleanup.');
        }
    }
    $paymentLinks = DB::table('nonprofit_project_payments')->where(fn ($q) => $q->whereIn('project_id', $ids)->orWhereIn('payment_id', $payments->pluck('id'))->orWhere('actor_id', $u->id))->get();
    foreach ($paymentLinks as $r) {
        if (! $ids->contains($r->project_id) || ! $payments->pluck('id')->contains($r->payment_id) || $r->actor_id !== $u->id) {
            throw new RuntimeException('A protected payment association prevents cleanup.');
        }
    }
    if (DB::table('nonprofit_pledge_receipts')->whereIn('payment_id', $payments->pluck('id'))->exists()) {
        throw new RuntimeException('A protected pledge uses this source payment.');
    }
    $resources = DB::table('nonprofit_project_resources')->where(fn ($q) => $q->whereIn('project_id', $ids)->orWhere('actor_id', $u->id)->orWhere(fn ($q) => $q->where('target_type', 'asset')->whereIn('target_id', $assets->pluck('id')))->orWhere(fn ($q) => $q->where('target_type', 'operations-order')->whereIn('target_id', $orders->pluck('id'))))->get();
    foreach ($resources as $r) {
        if (! $ids->contains($r->project_id) || $r->actor_id !== $u->id || ! match ($r->target_type) {
            'asset' => $assets->pluck('id')->contains($r->target_id),'operations-order' => $orders->pluck('id')->contains($r->target_id),default => false
        }) {
            throw new RuntimeException('A protected resource association prevents cleanup.');
        }
    }
    $history = DB::table('nonprofit_project_history')->where(fn ($q) => $q->whereIn('project_id', $ids)->orWhere('actor_id', $u->id))->get();
    foreach ($history as $r) {
        if (! $ids->contains($r->project_id) || $r->actor_id !== $u->id) {
            throw new RuntimeException('A protected project history prevents cleanup.');
        }
    }
    $workHistory = DB::table('crm_work_history')->whereIn('work_item_id', $workIds)->get();
    $financeHistory = DB::table('finance_record_history')->whereIn('record_id', $finance->pluck('id'))->get();
    $orderHistory = DB::table('operations_order_history')->whereIn('order_id', $orders->pluck('id'))->get();
    $assetHistory = DB::table('assets_history')->whereIn('asset_id', $assets->pluck('id'))->get();
    $relationHistory = DB::table('crm_identity_relationship_history')->whereIn('relationship_id', $relations->pluck('id'))->get();
    $identityHistory = DB::table('crm_identity_activity')->where(fn ($q) => $q->whereIn('contact_id', $contacts->pluck('id'))->orWhereIn('company_id', $companies->pluck('id')))->get();
    foreach ([$workHistory, $financeHistory, $orderHistory, $assetHistory, $relationHistory, $identityHistory] as $rows) {
        if ($rows->contains(fn ($r) => $r->actor_id !== $u->id)) {
            throw new RuntimeException('A protected historical actor prevents cleanup.');
        }
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'nonprofit')->whereIn('source', ['work-project-tasks', 'work-project-milestones'])->whereIn('record_id', $workIds)->get();
    if ($notices->contains(fn ($r) => $r->user_id !== $u->id)) {
        throw new RuntimeException('A protected reminder prevents cleanup.');
    }
    projectFixtureForeign('nonprofit_projects', $ids, ['nonprofit_project_work', 'nonprofit_project_budgets', 'nonprofit_project_meetings', 'nonprofit_project_resources', 'nonprofit_project_payments', 'nonprofit_project_documents', 'nonprofit_project_history']);
    projectFixtureForeign('crm_work_items', $workIds, ['nonprofit_project_work', 'nonprofit_project_meetings', 'crm_work_history']);
    projectFixtureForeign('assets_register', $assets->pluck('id'), ['assets_history']);
    projectFixtureForeign('operations_orders', $orders->pluck('id'), ['operations_order_history']);
    projectFixtureForeign('finance_records', $finance->pluck('id'), ['finance_payments', 'finance_record_history']);
    projectFixtureForeign('finance_payments', $payments->pluck('id'), []);
    projectFixtureForeign('crm_identity_relationships', $relations->pluck('id'), ['crm_identity_relationship_history', 'finance_records', 'operations_orders']);
    projectFixtureForeign('crm_contacts', $contacts->pluck('id'), ['crm_identity_relationships', 'crm_identity_activity']);
    projectFixtureForeign('crm_companies', $companies->pluck('id'), ['crm_identity_relationships', 'crm_identity_activity']);
    $groups = ['nonprofit_project_history' => $history, 'nonprofit_project_documents' => $documents, 'nonprofit_project_payments' => $paymentLinks, 'nonprofit_project_resources' => $resources, 'nonprofit_project_meetings' => $meetings, 'nonprofit_project_work' => $workLinks, 'nonprofit_project_budgets' => $budgets, 'nonprofit_projects' => $projects, 'crm_work_history' => $workHistory, 'crm_work_reminders' => $notices, 'crm_work_items' => $work, 'finance_record_history' => $financeHistory, 'finance_payments' => $payments, 'finance_records' => $finance, 'operations_order_history' => $orderHistory, 'operations_orders' => $orders, 'assets_history' => $assetHistory, 'assets_register' => $assets, 'crm_identity_relationship_history' => $relationHistory, 'crm_identity_relationships' => $relations, 'crm_identity_activity' => $identityHistory, 'crm_contacts' => $contacts, 'crm_companies' => $companies];
    if (collect($groups)->contains(fn ($r) => $r->isNotEmpty())) {
        $dir = storage_path('app/private/nonprofit-projects-fixture-recovery');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }$snapshot = $dir.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($snapshot, json_encode(array_map(fn ($r) => $r->all(), $groups), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($snapshot, 0600);
    }foreach ($groups as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }

    return ['cleaned' => $projects->count() + $work->count() + $assets->count() + $orders->count() + $finance->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
