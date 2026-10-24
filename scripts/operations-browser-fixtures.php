<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Operations\OperationsModule;
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
$actual = DB::selectOne('select current_database() as name, current_user as role');
if (config('database.connections.pgsql.port') != 55438 || $actual->name !== 'base_alpha' || $actual->role !== 'base_alpha') {
    throw new RuntimeException('Only the isolated PostgreSQL Alpha example is permitted.');
}
$mode = $argv[1] ?? '';
$project = $argv[2] ?? '';
if (! in_array($mode, ['setup', 'cleanup'], true) || ! in_array($project, ['desktop', 'mobile'], true)) {
    throw new RuntimeException('Choose setup or cleanup and desktop or mobile.');
}
$email = 'operations-'.$project.'@alpha.example.test';
$contactEmail = 'operations-contact-'.$project.'@alpha.example.test';
$companyEmail = 'operations-company-'.$project.'@alpha.example.test';
$user = User::where('email', $email)->first();
if ($user && $user->name !== 'Tamar Cohen') {
    throw new RuntimeException('A protected account uses the reserved fixture email.');
}
$result = DB::transaction(function () use ($mode, $project, $email, $contactEmail, $companyEmail, $user) {
    DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['basecrm-operations-fixtures']);
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
        $fixture = $user ?? User::factory()->create(['name' => 'Tamar Cohen', 'email' => $email, 'email_verified_at' => now()]);
        $fixture->forceFill(['active' => true])->save();
        $fixture->assignRole('Employee');
        $fixture->syncPermissions([...(new OperationsModule)->permissions(), 'identities.classifications.view', 'identities.classifications.manage', 'identities.classifications.assign']);
        $company ??= Company::create(['name' => 'Carmel Supply', 'email' => $companyEmail, 'phone' => '+1 202-555-0102', 'search_key' => 'carmelsupply', 'created_by' => $fixture->id]);
        $contact ??= Contact::create(['name' => 'Yael Shalev', 'email' => $contactEmail, 'phone' => '+1 202-555-0101', 'company_id' => $company->id, 'search_key' => 'yaelshalev', 'created_by' => $fixture->id]);
        $manifest = Installation::manifest();
        $manifest['modules'] = ['operations'];
        $manifest['capabilities']['sales'] = false;
        $path = storage_path('app/private/operations-browser-manifest.json');
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
        $today = now(Installation::settings()['timezone']);

        return ['email' => $email, 'owner_id' => $fixture->id, 'contact_id' => $contact->id, 'company_id' => $company->id, 'today' => $today->toDateString(), 'next' => $today->copy()->addDays(7)->toDateString(), 'manifest' => $path];
    }
    if (! $user) {
        return ['cleaned' => 0];
    }
    $requests = DB::table('crm_work_items')->where('module', 'operations')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    $routines = DB::table('crm_routines')->where('module', 'operations')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    $orders = DB::table('operations_orders')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhere('created_by', $user->id))->get();
    foreach (['requests' => $requests, 'routines' => $routines, 'orders' => $orders] as $kind => $records) {
        $expected = match ($kind) {
            'requests' => ['Arrange office access', 'Confirm delivery slot'], 'routines' => ['Weekly office readiness'], default => ['OPS-101']
        };
        foreach ($records as $row) {
            if ($row->owner_id !== $user->id || $row->created_by !== $user->id || ! in_array($row->title ?? $row->name ?? $row->reference, $expected, true) || ($kind !== 'orders' && $row->kind !== $kind)) {
                throw new RuntimeException('A protected Operations record references the fixture; cleanup refused.');
            }
        }
    }
    $relationships = DB::table('crm_identity_relationships')->where(fn ($q) => $q->where('contact_id', $contact?->id ?? 0)->orWhere('company_id', $company?->id ?? 0))->get();
    if ($relationships->contains(fn ($row) => $row->created_by !== $user->id || $row->responsible_id !== $user->id || ! in_array($row->role, ['customer', 'supplier'], true) || ($row->main_contact_id !== null && $row->main_contact_id !== $contact?->id))) {
        throw new RuntimeException('A protected classification references the fixture; cleanup refused.');
    }
    $requestIds = $requests->pluck('id');
    $routineIds = $routines->pluck('id');
    $orderIds = $orders->pluck('id');
    $relationshipIds = $relationships->pluck('id');
    $details = DB::table('operations_request_details')->whereIn('work_item_id', $requestIds)->get();
    if ($details->contains(fn ($row) => $row->customer_relationship_id !== null && ! $relationshipIds->contains($row->customer_relationship_id)) || $orders->contains(fn ($row) => ! $relationshipIds->contains($row->supplier_relationship_id))) {
        throw new RuntimeException('An external classification is linked to the fixture; cleanup refused.');
    }
    $workHistory = DB::table('crm_work_history')->where(fn ($q) => $q->whereIn('work_item_id', $requestIds)->orWhereIn('routine_id', $routineIds))->get();
    $completions = DB::table('crm_routine_completions')->whereIn('routine_id', $routineIds)->get();
    $orderHistory = DB::table('operations_order_history')->whereIn('order_id', $orderIds)->get();
    $receipts = DB::table('operations_order_receipts')->whereIn('order_id', $orderIds)->get();
    $relationshipHistory = DB::table('crm_identity_relationship_history')->whereIn('relationship_id', $relationshipIds)->get();
    $identityHistory = DB::table('crm_identity_activity')->where(fn ($q) => $q->where('contact_id', $contact?->id ?? 0)->orWhere('company_id', $company?->id ?? 0))->get();
    foreach ([$workHistory, $orderHistory, $receipts, $relationshipHistory, $identityHistory] as $history) {
        if ($history->contains(fn ($row) => $row->actor_id !== $user->id)) {
            throw new RuntimeException('A protected history actor references the fixture; cleanup refused.');
        }
    }
    if ($completions->contains(fn ($row) => $row->completed_by !== $user->id)) {
        throw new RuntimeException('A protected completion actor references the fixture; cleanup refused.');
    }
    $notices = DB::table('crm_work_reminders')->where('module', 'operations')->where(function ($q) use ($requestIds, $routineIds, $orderIds) {
        $q->where(fn ($q) => $q->where('source', 'work-requests')->whereIn('record_id', $requestIds))->orWhere(fn ($q) => $q->where('source', 'routine-routines')->whereIn('record_id', $routineIds))->orWhere(fn ($q) => $q->where('source', 'orders')->whereIn('record_id', $orderIds));
    })->get();
    if ($notices->contains(fn ($row) => $row->user_id !== $user->id)) {
        throw new RuntimeException('A protected reminder references the fixture; cleanup refused.');
    }
    $collections = ['crm_work_reminders' => $notices, 'operations_order_receipts' => $receipts, 'operations_order_history' => $orderHistory, 'operations_request_details' => $details, 'crm_work_history' => $workHistory, 'crm_routine_completions' => $completions, 'operations_orders' => $orders, 'crm_work_items' => $requests, 'crm_routines' => $routines, 'crm_identity_relationship_history' => $relationshipHistory, 'crm_identity_relationships' => $relationships, 'crm_identity_activity' => $identityHistory];
    $snapshot = array_map(fn ($rows) => $rows->all(), $collections);
    $snapshot['crm_contacts'] = $contact ? [$contact->getAttributes()] : [];
    $snapshot['crm_companies'] = $company ? [$company->getAttributes()] : [];
    if ($contact || $company || $requests->isNotEmpty() || $orders->isNotEmpty() || $routines->isNotEmpty()) {
        $directory = storage_path('app/private/operations-fixture-recovery');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.$project.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)).'.json';
        file_put_contents($path, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        chmod($path, 0600);
    }
    foreach ($collections as $table => $rows) {
        DB::table($table)->whereIn('id', $rows->pluck('id'))->delete();
    }
    // Restrictive foreign keys abort this whole transaction if any protected record still references an identity.
    if ($contact) {
        DB::table('crm_contacts')->where('id', $contact->id)->delete();
    }
    if ($company) {
        DB::table('crm_companies')->where('id', $company->id)->delete();
    }

    return ['cleaned' => $requests->count() + $orders->count() + $routines->count()];
});
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
