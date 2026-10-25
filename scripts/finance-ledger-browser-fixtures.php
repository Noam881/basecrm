<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Finance\LedgerCapability;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.alpha');
$app->make(Kernel::class)->bootstrap();
FictionalCrmFixtures::assertLocalExample('alpha');
$actual = DB::selectOne('select current_database() as name,current_user as role');
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha') {
    throw new RuntimeException('Only the isolated PostgreSQL Alpha example is permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup/cleanup and desktop/mobile.');
}$email = 'finance-ledger-'.$project.'@alpha.example.test';
$contactEmail = 'finance-ledger-contact-'.$project.'@alpha.example.test';
$companyEmail = 'finance-ledger-company-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Daniel Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $companyEmail, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-finance-ledger-fixtures']);
    $contact = Contact::where('email', $contactEmail)->first();
    $company = Company::where('email', $companyEmail)->first();
    foreach ([$contact, $company] as $identity) {
        if ($identity && (! $user || $identity->created_by !== $user->id || ! in_array($identity->name, ['Yael Shalev', 'Carmel Supply'], true))) {
            throw new RuntimeException('A protected identity uses the reserved fixture email.');
        }
    }
    if ($mode === 'setup') {
        Artisan::call('crm:install');
        RateLimiter::clear(md5('magic-login-request'.'magic-request:email:alpha:'.hash('sha256', $email)));
        $fixture = $user ?? User::factory()->create(['name' => 'Daniel Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions([...(new LedgerCapability('collections'))->permissions(), ...(new LedgerCapability('expenses'))->permissions(), 'identities.classifications.view', 'identities.classifications.manage', 'identities.classifications.assign']);
        $company ??= Company::create(['name' => 'Carmel Supply', 'email' => $companyEmail, 'phone' => '+1 202-555-0102', 'search_key' => 'carmelsupply', 'created_by' => $fixture->id]);
        $contact ??= Contact::create(['name' => 'Yael Shalev', 'email' => $contactEmail, 'phone' => '+1 202-555-0101', 'company_id' => $company->id, 'search_key' => 'yaelshalev', 'created_by' => $fixture->id]);
        $manifest = Installation::manifest();
        $manifest['modules'] = ['finance'];
        $manifest['capabilities'] = ['sales' => false, 'finance:collections' => true, 'finance:expenses' => true, 'finance:payroll' => false];
        $path = storage_path('app/private/finance-ledger-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
        $today = now(Installation::settings()['timezone']);

        return ['email' => $email, 'owner_id' => $fixture->id, 'contact_id' => $contact->id, 'company_id' => $company->id, 'today' => $today->toDateString(), 'next' => $today->copy()->addDays(7)->toDateString(), 'currency' => Installation::settings()['currency'], 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }$records = DB::table('finance_records')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    foreach ($records as $r) {
        if ($r->owner_id !== $user->id || $r->created_by !== $user->id || ! in_array($r->reference, ['CH-101', 'EXP-101'], true) || ! in_array($r->kind, ['charge', 'expense'], true)) {
            throw new RuntimeException('A protected financial record references this fixture; cleanup refused.');
        }
    }
    $relationships = DB::table('crm_identity_relationships')->where(fn ($q) => $q->where('contact_id', $contact?->id ?? 0)->orWhere('company_id', $company?->id ?? 0))->get();
    if ($relationships->contains(fn ($r) => $r->created_by !== $user->id || $r->responsible_id !== $user->id || ! in_array($r->role, ['customer', 'supplier'], true) || ($r->main_contact_id !== null && $r->main_contact_id !== $contact?->id))) {
        throw new RuntimeException('A protected classification references the fixture; cleanup refused.');
    }$relationshipIds = $relationships->pluck('id');
    if ($records->contains(fn ($r) => ! $relationshipIds->contains($r->relationship_id))) {
        throw new RuntimeException('An external classification is linked to the fixture; cleanup refused.');
    }
    $ids = $records->pluck('id');
    $payments = DB::table('finance_payments')->whereIn('record_id', $ids)->get();
    $history = DB::table('finance_record_history')->whereIn('record_id', $ids)->get();
    $relationshipHistory = DB::table('crm_identity_relationship_history')->whereIn('relationship_id', $relationshipIds)->get();
    $identityHistory = DB::table('crm_identity_activity')->where(fn ($q) => $q->where('contact_id', $contact?->id ?? 0)->orWhere('company_id', $company?->id ?? 0))->get();
    foreach ([$payments, $history, $relationshipHistory, $identityHistory] as $rows) {
        if ($rows->contains(fn ($r) => $r->actor_id !== $user->id)) {
            throw new RuntimeException('A protected history actor references the fixture; cleanup refused.');
        }
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'finance')->whereIn('source', ['collections', 'expenses'])->whereIn('record_id', $ids)->get();
    if ($notices->contains(fn ($r) => $r->user_id !== $user->id)) {
        throw new RuntimeException('A protected financial reminder references the fixture; cleanup refused.');
    }
    $collections = ['crm_work_reminders' => $notices, 'finance_payments' => $payments, 'finance_record_history' => $history, 'finance_records' => $records, 'crm_identity_relationship_history' => $relationshipHistory, 'crm_identity_relationships' => $relationships, 'crm_identity_activity' => $identityHistory];
    $snapshot = array_map(fn ($rows) => $rows->all(), $collections);
    $snapshot['crm_contacts'] = $contact ? [$contact->getAttributes()] : [];
    $snapshot['crm_companies'] = $company ? [$company->getAttributes()] : [];
    if ($contact || $company || $records->isNotEmpty()) {
        $directory = storage_path('app/private/finance-ledger-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }$path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }
    foreach ($collections as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }if ($contact) {
        DB::table('crm_contacts')->where('id', $contact->id)->delete();
    }if ($company) {
        DB::table('crm_companies')->where('id', $company->id)->delete();
    }

    return ['cleaned' => $records->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
